<?
$plugin = "preclear.disk";
require_once ("webGui/include/Helpers.php");

function tmux_is_session($name) {
  exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
  return in_array($name, $screens);
}
function tmux_new_session($name) {
  if (! tmux_is_session($name)) {
    exec("/usr/bin/tmux new-session -d -x 140 -y 35 -s '${name}' 2>/dev/null");
  }
}
function tmux_get_session($name) {
  return (tmux_is_session($name)) ? shell_exec("/usr/bin/tmux capture-pane -t '${name}' 2>/dev/null;/usr/bin/tmux show-buffer 2>&1") : "";
}
function tmux_send_command($name, $cmd) {
  exec("/usr/bin/tmux send -t '$name' '$cmd' ENTER 2>/dev/null");
}
function tmux_kill_window($name) {
  if (tmux_is_session($name)) {
    exec("/usr/bin/tmux kill-window -t '${name}' 2>/dev/null");
  }
}
function listDir($root) {
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, 
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();
  foreach ($iter as $path => $fileinfo) {
    if (! $fileinfo->isDir()) $paths[] = $path;
  }
  return $paths;
}
function get_unasigned_disks() {
  $disks = array();
  $paths=listDir("/dev/disk/by-id");
  natsort($paths);
  $usb_disks = array();
  foreach (listDir("/dev/disk/by-path") as $v) if (preg_match("#usb#", $v)) $usb_disks[] = realpath($v);
  $unraid_flash = realpath("/dev/disk/by-label/UNRAID");
  $unraid_disks = array();
  foreach (parse_ini_string(shell_exec('/root/mdcmd status 2>/dev/null')) as $k => $v) {
    if (strpos($k, "rdevName") !== FALSE && strlen($v)) {
      $unraid_disks[] = realpath("/dev/$v");
    }
  }
  $unraid_cache = array();
  foreach (parse_ini_file("/boot/config/disk.cfg") as $k => $v) {
    if (strpos($k, "cacheId") !== FALSE) {
      foreach ( preg_grep("#".$v."#i", $paths) as $c) $unraid_cache[] = realpath($c);
    }
  }
  foreach ($paths as $d) {
    $path = realpath($d);
    if (preg_match("#(ata|usb|scsi)(.(?!part))*$#", $d) && ! in_array($path, $unraid_disks)){
      if ($m = array_values(preg_grep("#$d.*-part\d+#", $paths))) {
        natsort($m);
        foreach ($m as $k => $v) $m_real[$k] = realpath($v);
        if (strpos($d, "ata") !== FALSE && ! count(array_intersect($unraid_cache, $m_real)) && ! in_array($path, $usb_disks)) {
          $disks[$d] = array('device'=>$path,'type'=>'ata','partitions'=>$m);
        } else if ( in_array($path, $usb_disks) && ! in_array($unraid_flash, $m_real)) {
          $disks[$d] = array('device'=>$path,'type'=>'usb','partitions'=>$m);
        }
      } else {
        $disks[$d] = array('device'=>$path,'type'=>'und','partitions'=>array());
      }
    }
  }
  return $disks;
}

function is_mounted($dev) {
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}

function get_all_disks_info($bus="all") {
  // $d1 = time();
  $disks = get_unasigned_disks();
  foreach ($disks as $key => $disk) {
    if ($disk['type'] != $bus && $bus != "all") continue;
    $disk['temperature'] = get_temp($key);
    $disk['size'] = intval(trim(shell_exec("blockdev --getsize64 ${key} 2>/dev/null")));
    $disk = array_merge($disk, get_disk_info($key));
    $disks[$key] = $disk;
  }
  // debug("get_all_disks_info: ".(time() - $d1));
  usort($disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
  return $disks;
}

function get_disk_info($device, $reload=FALSE){
  $disk = array();
  $attrs = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device ) 2>/dev/null"));
  $device = realpath($device);
    $disk['serial']       = $attrs['ID_SERIAL'];
    $disk['serial_short'] = $attrs['ID_SERIAL_SHORT'];
    $disk['device']       = $device;
    return $disk;
}

function is_disk_running($dev) {
  $state = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
  return ($state == 0) ? TRUE : FALSE;
}

function get_temp($dev) {
  if (is_disk_running($dev)) {
    $temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
    return (is_numeric($temp)) ? $temp : "*";
  }
  return "*";
}

if (isset($_POST['display'])) $display = $_POST['display'];

switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    echo "<table class='preclear custom_head'><thead><tr><td>Device</td><td>Identification</td><td>Temp</td><td>Preclear Status</td></tr></thead>";
    echo "<tbody>";
    if ( count($disks) ) {
      $odd="odd";
      foreach ($disks as $disk) {
        $disk_mounted = false;
        foreach ($disk['partitions'] as $p) if (is_mounted(realpath($p))) $disk_mounted = TRUE;
        $temp = my_temp($disk['temperature']);
        $disk_name = basename($disk['device']);
        $serial = $disk['serial'];
        echo "<tr class='$odd'>";
        printf( "<td><img src='/webGui/images/%s'> %s</td>", ( is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" ), $disk_name);
        echo "<td><span class='toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i>".($p?"<span style='margin:4px;'></span>":"<i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>").$serial."</td>";
        echo "<td>{$temp}</td>";
        $status = $disk_mounted ? "Disk mounted" : "<a class='exec' onclick='start_preclear(\"".$serial."\",\"{$disk_name}\")'>Start Preclear</a>";
        if (tmux_is_session("preclear_disk_{$disk_name}")) {
          $status = "{$status}<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
          $status = "{$status}<a title='Clear' style='color:#CC0000;' class='exec' onclick='remove_session(\"{$disk_name}\");'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
        }
        if (is_file("/tmp/preclear_stat_{$disk_name}")) {
          $preclear = explode("|", file_get_contents("/tmp/preclear_stat_{$disk_name}"));
          if (count($preclear) > 3) {
            if (file_exists( "/proc/".trim($preclear[3]))) {
              $status = "<span style='color:#478406;'>{$preclear[2]}</span>";
              if (tmux_is_session("preclear_disk_{$disk_name}")) $status = "$status<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
              $status = "{$status}<a title='Stop Preclear' style='color:#CC0000;' class='exec rm_preclear' onclick='stop_preclear(\"{$serial}\",\"{$disk_name}\");'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
            } else {
              $status = "<span style='color:#CC0000;'>{$preclear[2]} <a class='exec' style='color:#CC0000;font-weight:bold;' onclick='clear_preclear(\"{$disk_name}\");' title='Clear stats'> <i class='glyphicon glyphicon-remove hdd'></i></a></span>";
              if (tmux_is_session("preclear_disk_{$disk_name}")) $status = "$status<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
            } 
          } else {
            $status = "{$preclear[2]} <span class='rm_preclear' onclick='stop_preclear(\"{$disk_name}\");'> [clear]</span>";
          } 
        }
        echo "<td>$status</td>";
        echo "</tr>";
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    } else {
      echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    echo "</tbody></table><div style='min-height:20px;'></div>";
    break;
  case 'start_preclear':
    $device = urldecode($_POST['device']);
    $op       = (isset($_POST['op']) && $_POST['op'] != "0") ? " ".urldecode($_POST['op']) : "";
    $mail     = (isset($_POST['-M']) && $_POST['-M'] > 0) ? " -M ".urldecode($_POST['-M']) : "";
    $passes   = isset($_POST['-c']) ? " -c ".urldecode($_POST['-c']) : "";
    $read_sz  = (isset($_POST['-r']) && $_POST['-r'] != 0) ? " -r ".urldecode($_POST['-r']) : "";
    $write_sz = (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".urldecode($_POST['-w']) : "";
    $pre_read = (isset($_POST['-W']) && $_POST['-W'] == "on") ? " -W" : "";
    if (! $op){
      $cmd = "/usr/local/sbin/preclear_disk.sh -Y{$op}{$mail}{$passes}{$read_sz}{$write_sz}{$pre_read} /dev/$device";
    } else {
      $cmd = "/usr/local/sbin/preclear_disk.sh -Y{$op} /dev/$device";
    }

    echo $cmd;
    tmux_kill_window("preclear_disk_{$device}");
    tmux_new_session("preclear_disk_{$device}");
    tmux_send_command("preclear_disk_{$device}", $cmd);
    break;
  case 'stop_preclear':
    $device = urldecode($_POST['device']);
    tmux_kill_window("preclear_disk_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;
  case 'clear_preclear':
    $device = urldecode($_POST['device']);
    @unlink("/tmp/preclear_stat_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;
}
switch ($_GET['action']) {
  case 'show_preclear':
    $device = urldecode($_GET['device']);
    echo "<script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>";
    echo str_replace("\n", "<br>", tmux_get_session("preclear_disk_".$device));
    echo "<script>document.title='Preclear for disk /dev/{$device} ';$(function(){setTimeout('location.reload()',10000);});</script>";
    break;
}
?>