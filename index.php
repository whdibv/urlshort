<?php
/**
 * 短链接跳转服务
 * 访问 /{短码} → 301 跳转（可加密）
 * 访问 / → 创建短链接（自动复制 + 二维码）
 * 访问 /admin → 管理后台
 * API: POST /api/create（url 必填，code/password/token 可选）
 */
session_start();
$data_file = __DIR__ . '/short.json';
$config_file = __DIR__ . '/admin_config.php';
$admin_pass = 'please-change-me'; // ⚠️ 默认密码，部署后请立即修改（推荐在 admin_config.php 中设置）
$site_links = array(); // 底部站点链接（在 admin_config.php 中配置）
if (is_file($config_file)) {
    $cfg = require $config_file;
    if (isset($cfg['password'])) $admin_pass = $cfg['password'];
    if (isset($cfg['site_links']) && is_array($cfg['site_links'])) $site_links = $cfg['site_links'];
}

function load_short($file) {
    $d = json_decode(@file_get_contents($file), true);
    if (!is_array($d) || !isset($d['links']) || !is_array($d['links'])) {
        return array('links' => array());
    }
    return $d;
}
function save_short($file, $d) {
    @file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function gen_code($len = 6) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $len; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}
function json_out($code, $msg, $extra = array()) {
    $out = array('code' => $code, 'msg' => $msg);
    foreach ($extra as $k => $v) $out[$k] = $v;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
// 获取访客 IP（原生支持 IPv4/IPv6）
function get_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}
// 查询 IP 归属地（netart.cn，带本地缓存）
function lookup_ip_geo($ip, &$data, $data_file) {
    if (isset($data['ip_cache'][$ip])) {
        return $data['ip_cache'][$ip];
    }
    $label = '未知';
    $ch = @curl_init('https://ip.netart.cn/?ip=' . urlencode($ip));
    if ($ch) {
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $resp = @curl_exec($ch);
        curl_close($ch);
        $info = json_decode($resp, true);
        if (is_array($info) && isset($info['ip'])) {
            $parts = array();
            if (isset($info['country']['name'])) $parts[] = $info['country']['name'];
            if (!empty($info['subdivision'])) $parts[] = $info['subdivision'];
            if (!empty($info['city'])) $parts[] = $info['city'];
            $isp = '';
            if (!empty($info['geo_cn']['isp'])) $isp = $info['geo_cn']['isp'];
            elseif (!empty($info['as']['info'])) $isp = $info['as']['info'];
            if ($isp !== '') $parts[] = $isp;
            $label = $parts ? implode(' ', $parts) : '未知';
        }
    }
    // 缓存（限制 800 条防膨胀，超出删最早记录）
    $data['ip_cache'][$ip] = $label;
    if (count($data['ip_cache']) > 800) {
        array_shift($data['ip_cache']);
    }
    save_short($data_file, $data);
    return $label;
}

$data = load_short($data_file);
if (!isset($data['settings']) || !is_array($data['settings'])) {
    $data['settings'] = array('allow_guest' => true, 'guest_limit' => 10, 'guest_expire_days' => 7);
}
if (!isset($data['guest_usage']) || !is_array($data['guest_usage'])) {
    $data['guest_usage'] = array();
}
if (!isset($data['api_tokens']) || !is_array($data['api_tokens'])) {
    $data['api_tokens'] = array();
}
if (!isset($data['ip_cache']) || !is_array($data['ip_cache'])) {
    $data['ip_cache'] = array();
}
$allow_guest = !empty($data['settings']['allow_guest']);
$guest_limit = (int)(isset($data['settings']['guest_limit']) ? $data['settings']['guest_limit'] : 10);
if ($guest_limit < 1) $guest_limit = 1;
$guest_expire_days = (int)(isset($data['settings']['guest_expire_days']) ? $data['settings']['guest_expire_days'] : 7);
if ($guest_expire_days < 0) $guest_expire_days = 0;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($uri, '/');
$error = '';
$result = '';
$is_admin = !empty($_SESSION['short_admin']);
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $host;

// ========== API ==========
if (preg_match('#^api/([a-zA-Z]+)$#', $path, $api_m)) {
    if ($api_m[1] !== 'create') json_out(0, '不支持的 API 操作');
    header('Content-Type: application/json; charset=utf-8');
    $long = trim(isset($_REQUEST['url']) ? $_REQUEST['url'] : '');
    $custom = trim(isset($_REQUEST['code']) ? $_REQUEST['code'] : '');
    $pwd = trim(isset($_REQUEST['password']) ? $_REQUEST['password'] : '');
    $token = trim(isset($_REQUEST['token']) ? $_REQUEST['token'] : '');
    // token 认证：管理密码 或 已生成的 API token
    $api_admin = false;
    if ($token !== '') {
        if ($token === $admin_pass || in_array($token, $data['api_tokens'])) {
            $api_admin = true;
        }
    }

    if ($long === '' || !preg_match('#^https?://#i', $long)) json_out(0, 'url 无效（需 http/https 开头）');
    if (!$api_admin && !$allow_guest) json_out(0, '访客暂不可创建短链接');
    $used = 0; $today = date('Y-m-d'); $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    if (!$api_admin) {
        $used = isset($data['guest_usage'][$today][$ip]) ? (int)$data['guest_usage'][$today][$ip] : 0;
        if ($used >= $guest_limit) json_out(0, '今日创建已达上限（' . $guest_limit . ' 个）');
    }
    $code = $custom !== '' ? $custom : gen_code();
    if ($custom !== '' && !preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $code)) json_out(0, '短码只能含字母数字-_，2~20位');
    if (isset($data['links'][$code])) json_out(0, '短码「' . $code . '」已被占用');

    $data['links'][$code] = array(
        'url' => $long,
        'created' => date('Y-m-d H:i'),
        'hits' => 0,
        'by' => $api_admin ? 'admin' : 'guest',
        'creator_ip' => $ip,
        'expires' => ($api_admin || $guest_expire_days <= 0) ? null : (time() + $guest_expire_days * 86400),
        'password' => $pwd !== '' ? md5($pwd) : '',
    );
    if (!$api_admin) {
        $data['guest_usage'][$today][$ip] = $used + 1;
    }
    save_short($data_file, $data);
    json_out(200, '创建成功', array(
        'short_url' => $base . '/' . $code,
        'code' => $code,
        'url' => $long,
        'password' => $pwd !== '',
        'expires' => isset($data['links'][$code]['expires']) ? $data['links'][$code]['expires'] : null,
    ));
}

// ========== 创建短链接（首页 POST）==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($path === '' || $path === 'index.php')) {
    $long = trim(isset($_POST['url']) ? $_POST['url'] : '');
    $custom = trim(isset($_POST['code']) ? $_POST['code'] : '');
    $pwd = trim(isset($_POST['password']) ? $_POST['password'] : '');
    if ($long === '' || !preg_match('#^https?://#i', $long)) {
        $error = '请输入有效的 http/https 链接';
    } elseif (!$is_admin && !$allow_guest) {
        $error = '访客暂不可创建短链接';
    } else {
        if (!$is_admin) {
            $today = date('Y-m-d');
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
            $used = isset($data['guest_usage'][$today][$ip]) ? (int)$data['guest_usage'][$today][$ip] : 0;
            if ($used >= $guest_limit) {
                $error = '今日创建已达上限（' . $guest_limit . ' 个）';
            }
        }
        if ($error === '') {
            $code = $custom !== '' ? $custom : gen_code();
            if ($custom !== '' && !preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $code)) {
                $error = '自定义短码只能含字母、数字、-、_，2~20 位';
            } elseif (isset($data['links'][$code])) {
                $error = '短码「' . $code . '」已被占用';
            } else {
                $data['links'][$code] = array(
                    'url' => $long,
                    'created' => date('Y-m-d H:i'),
                    'hits' => 0,
                    'by' => $is_admin ? 'admin' : 'guest',
                    'creator_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0',
                    'expires' => ($is_admin || $guest_expire_days <= 0) ? null : (time() + $guest_expire_days * 86400),
                    'password' => $pwd !== '' ? md5($pwd) : '',
                );
                if (!$is_admin) {
                    $data['guest_usage'][$today][$ip] = $used + 1;
                }
                save_short($data_file, $data);
                $result = $code;
            }
        }
    }
}

// ========== 管理逻辑 ==========
if ($path === 'admin') {
    if (isset($_POST['login'])) {
        if (isset($_POST['password']) && $_POST['password'] === $admin_pass) {
            $_SESSION['short_admin'] = true;
            header('Location: /admin');
            exit;
        } else {
            $error = '密码错误';
        }
    }
    if (isset($_GET['logout'])) {
        unset($_SESSION['short_admin']);
        session_destroy();
        header('Location: /admin');
        exit;
    }
    $is_admin = !empty($_SESSION['short_admin']);
    if ($is_admin) {
        if (isset($_POST['action']) && $_POST['action'] === 'del' && isset($_POST['code'])) {
            $code = $_POST['code'];
            if (isset($data['links'][$code])) {
                unset($data['links'][$code]);
                save_short($data_file, $data);
                header('Location: /admin?ok=del');
                exit;
            }
        }
        if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['code']) && isset($_POST['url'])) {
            $code = $_POST['code'];
            $new_url = trim($_POST['url']);
            if (isset($data['links'][$code]) && preg_match('#^https?://#i', $new_url)) {
                $data['links'][$code]['url'] = $new_url;
                save_short($data_file, $data);
                header('Location: /admin?ok=edit');
                exit;
            }
        }
        if (isset($_POST['action']) && $_POST['action'] === 'setting') {
            $data['settings']['allow_guest'] = isset($_POST['allow_guest']) && $_POST['allow_guest'] === '1';
            $limit = (int)trim(isset($_POST['guest_limit']) ? $_POST['guest_limit'] : '10');
            if ($limit < 1) $limit = 1;
            if ($limit > 100) $limit = 100;
            $data['settings']['guest_limit'] = $limit;
            $exp_days = (int)trim(isset($_POST['guest_expire_days']) ? $_POST['guest_expire_days'] : '7');
            if ($exp_days < 0) $exp_days = 0;
            if ($exp_days > 365) $exp_days = 365;
            $data['settings']['guest_expire_days'] = $exp_days;
            save_short($data_file, $data);
            header('Location: /admin?ok=setting');
            exit;
        }
        // 生成 API token
        if (isset($_POST['action']) && $_POST['action'] === 'gen_token') {
            $new_token = bin2hex(random_bytes(16));
            $data['api_tokens'][] = $new_token;
            save_short($data_file, $data);
            header('Location: /admin?ok=token');
            exit;
        }
        // 删除 API token
        if (isset($_POST['action']) && $_POST['action'] === 'del_token' && isset($_POST['token'])) {
            $del = $_POST['token'];
            $data['api_tokens'] = array_values(array_filter($data['api_tokens'], function($t) use ($del) { return $t !== $del; }));
            save_short($data_file, $data);
            header('Location: /admin?ok=token');
            exit;
        }
        // IP 归属地查询（JSON 接口，前端异步调用）
        if (isset($_GET['lookup']) && $_GET['lookup'] === '1' && isset($_GET['ip'])) {
            header('Content-Type: application/json; charset=utf-8');
            $qip = trim($_GET['ip']);
            if (!filter_var($qip, FILTER_VALIDATE_IP)) {
                json_out(0, '无效 IP');
            }
            $label = lookup_ip_geo($qip, $data, $data_file);
            json_out(200, 'ok', array('ip' => $qip, 'label' => $label));
        }
    }
}

// ========== 短码跳转 ==========
if ($path !== '' && $path !== 'admin' && $path !== 'index.php' && isset($data['links'][$path])) {
    $l = $data['links'][$path];
    // 过期检查
    if (isset($l['expires']) && $l['expires'] && $l['expires'] < time()) {
        unset($data['links'][$path]);
        save_short($data_file, $data);
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit('链接已过期');
    }
    // 密码检查
    if (!empty($l['password'])) {
        $verified = false;
        if (isset($_SESSION['short_pwd'][$path])) {
            $verified = true;
        } elseif (isset($_GET['pwd']) && md5($_GET['pwd']) === $l['password']) {
            $verified = true;
            $_SESSION['short_pwd'][$path] = true;
        } elseif (isset($_POST['pwd']) && md5($_POST['pwd']) === $l['password']) {
            $verified = true;
            $_SESSION['short_pwd'][$path] = true;
        }
        if (!$verified) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>访问密码</title><style>'
                . 'body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(160deg,#eef4ff,#f8fafc);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0}'
                . '.box{background:#fff;border-radius:16px;padding:32px;box-shadow:0 8px 30px rgba(15,23,42,.08);width:320px;text-align:center}'
                . 'h1{font-size:18px;color:#1e293b;margin:0 0 8px}.sub{font-size:13px;color:#94a3b8;margin:0 0 20px}'
                . 'input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;box-sizing:border-box;text-align:center}'
                . 'input:focus{outline:2px solid #99f6e4;border-color:#0d9488}'
                . 'button{width:100%;margin-top:12px;padding:10px;border:0;border-radius:10px;background:#0d9488;color:#fff;font-size:14px;cursor:pointer}'
                . 'button:hover{background:#0f766e}.err{color:#e11d48;font-size:13px;margin:0 0 10px}</style></head><body><div class="box">'
                . '<h1>🔒 链接已加密</h1><p class="sub">请输入访问密码</p>'
                . (isset($_POST['pwd']) ? '<p class="err">密码错误，请重试</p>' : '')
                . '<form method="post"><input type="password" name="pwd" placeholder="访问密码" autofocus><button type="submit">进入链接</button></form>'
                . '</div></body></html>';
            exit;
        }
    }
    // 记录访问 IP（去重计数，单链接最多 100 个，超出淘汰最久未访）
    $ip = get_client_ip();
    $ips = isset($l['ips']) && is_array($l['ips']) ? $l['ips'] : array();
    if (isset($ips[$ip])) {
        $ips[$ip]['n']++;
        $ips[$ip]['last'] = time();
    } else {
        $ips[$ip] = array('n' => 1, 'first' => time(), 'last' => time());
        if (count($ips) > 100) {
            uasort($ips, function($a, $b) { return $a['last'] <=> $b['last']; });
            array_shift($ips);
        }
    }
    $data['links'][$path]['ips'] = $ips;
    $data['links'][$path]['hits'] = (isset($l['hits']) ? $l['hits'] : 0) + 1;
    save_short($data_file, $data);
    header('Location: ' . $l['url'], true, 301);
    exit;
}

// ========== 统计 ==========
$total_links = count($data['links']);
$total_hits = 0;
$guest_links = 0;
foreach ($data['links'] as $l) {
    $total_hits += isset($l['hits']) ? (int)$l['hits'] : 0;
    if (isset($l['by']) && $l['by'] === 'guest') $guest_links++;
}
$today = date('Y-m-d');
$guest_today = 0;
if (isset($data['guest_usage'][$today])) {
    foreach ($data['guest_usage'][$today] as $ip => $n) $guest_today += (int)$n;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $path === 'admin' ? '短链接管理' : '短链接'; ?></title>
<meta name="description" content="简洁好用的自建短链接服务">
<meta name="robots" content="noindex,nofollow">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: -apple-system, "PingFang SC", "Microsoft YaHei", "Segoe UI", sans-serif;
  background: linear-gradient(160deg, #eef4ff 0%, #f8fafc 45%, #eefaf4 100%);
  min-height: 100vh; color: #1e293b; padding: 40px 20px;
  display: flex; align-items: flex-start; justify-content: center;
}
.wrap { width: 100%; max-width: 680px; }
.logo {
  width: 60px; height: 60px; border-radius: 18px; margin: 0 auto 14px;
  background: linear-gradient(135deg, #38bdf8, #1d9e75);
  display: flex; align-items: center; justify-content: center;
  font-size: 24px; font-weight: 700; color: #fff;
  box-shadow: 0 8px 24px rgba(29, 158, 117, .25);
}
h1 { font-size: 20px; text-align: center; }
.sub { font-size: 13px; color: #94a3b8; text-align: center; margin: 6px 0 24px; }
.card {
  background: #fff; border-radius: 16px; padding: 24px;
  box-shadow: 0 2px 12px rgba(15,23,42,.06); margin-bottom: 16px;
}
label { display: block; font-size: 13px; color: #64748b; margin: 10px 0 4px; }
input[type=text], input[type=password], input[type=url], input[type=number] {
  width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;
  font-size: 14px; box-sizing: border-box;
}
input:focus { outline: 2px solid #99f6e4; border-color: #0d9488; }
.btn {
  display: inline-block; padding: 10px 22px; border: 0; border-radius: 10px;
  font-size: 14px; cursor: pointer; background: #0d9488; color: #fff;
}
.btn:hover { background: #0f766e; }
.btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 8px; }
.btn-red { background: #f43f5e; }
.btn-red:hover { background: #e11d48; }
.btn-ghost { background: #f1f5f9; color: #475569; }
.btn-ghost:hover { background: #e2e8f0; }
.err { background: #fff1f2; color: #e11d48; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
.ok { background: #f0fdfa; color: #0f766e; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
.result {
  background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 12px;
  padding: 14px; margin-top: 14px;
}
.result .r-label { font-size: 12px; color: #0f766e; margin-bottom: 6px; }
.result .r-url {
  font-size: 15px; font-weight: 600; color: #0d9488; word-break: break-all;
  padding: 8px 10px; background: #fff; border-radius: 8px; border: 1px solid #99f6e4;
}
.result .r-copy { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.result .r-status { font-size: 12px; color: #0f766e; }
.result .r-qr { margin-top: 12px; display: flex; align-items: center; gap: 14px; }
.result .r-qr .qr-box { background: #fff; border-radius: 10px; padding: 8px; border: 1px solid #99f6e4; }
.result .r-qr .qr-tip { font-size: 12px; color: #0f766e; }
.stats { display: flex; gap: 12px; margin-bottom: 16px; }
.stat {
  flex: 1; background: #fff; border-radius: 12px; padding: 14px; text-align: center;
  box-shadow: 0 2px 8px rgba(15,23,42,.05);
}
.stat b { font-size: 22px; color: #0d9488; display: block; }
.stat span { font-size: 12px; color: #94a3b8; }
.search-box { margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
th { color: #94a3b8; font-weight: 500; font-size: 12px; }
.code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #0d9488; font-weight: 600; }
.url-cell { color: #64748b; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
.muted { color: #94a3b8; font-size: 12px; }
.ops { white-space: nowrap; }
.ops .btn-sm { margin: 2px 2px; }
.ops form { display: inline; }
.edit-row { display: none; background: #f8fafc; }
.edit-row td { padding: 8px; }
.edit-row form { display: flex; gap: 8px; }
.edit-row input { flex: 1; padding: 6px 10px; font-size: 13px; }
.empty { text-align: center; color: #94a3b8; padding: 24px 0; font-size: 13px; }
.actions { text-align: center; font-size: 13px; color: #94a3b8; margin-top: 4px; }
.actions a { color: #0d9488; text-decoration: none; }
.actions a:hover { text-decoration: underline; }
.badge { display: inline-block; font-size: 11px; border-radius: 99px; padding: 1px 8px; }
.ip-label { font-size: 12px; color: #334155; word-break: break-all; }
#ipMaskBody table td { font-size: 12px; }
.badge-admin { background: #e8f0fe; color: #1f6feb; }
.badge-guest { background: #fdf3e3; color: #b45309; }
.switch { position: relative; display: inline-block; width: 44px; height: 24px; vertical-align: middle; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute; cursor: pointer; inset: 0; border-radius: 24px;
  background: #cbd5e1; transition: .2s;
}
.slider::before {
  content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px;
  background: #fff; border-radius: 50%; transition: .2s;
}
.switch input:checked + .slider { background: #0d9488; }
.switch input:checked + .slider::before { transform: translateX(20px); }
.setting-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
.setting-row:last-child { border-bottom: 0; }
.setting-row .s-label { font-size: 14px; color: #334155; }
.setting-row .s-desc { font-size: 12px; color: #94a3b8; margin-top: 2px; }
.num-input { width: 90px !important; display: inline-block; }
.lock { color: #f59e0b; }
/* 二维码弹层 */
.mask {
  display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5);
  align-items: center; justify-content: center; z-index: 99;
}
.mask.show { display: flex; }
.qr-modal {
  background: #fff; border-radius: 16px; padding: 24px; text-align: center;
  max-width: 320px; width: 90%;
}
.qr-modal h3 { font-size: 15px; margin-bottom: 12px; color: #1e293b; }
.qr-modal .qr-wrap { display: flex; justify-content: center; margin-bottom: 12px; }
.qr-modal .qr-url { font-size: 12px; color: #64748b; word-break: break-all; margin-bottom: 14px; }
</style>
</head>
<body>
<div class="wrap">
<?php if($path === 'admin'){ ?>
  <?php if(!$is_admin){ ?>
  <div class="logo">u</div>
  <h1>短链接管理</h1>
  <p class="sub">请输入管理密码</p>
  <div class="card">
    <?php if($error){ ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php } ?>
    <form method="post">
      <input type="password" name="password" placeholder="管理密码" required autofocus>
      <div style="margin-top:14px;"><button class="btn" type="submit" name="login" value="1">登录</button></div>
    </form>
  </div>
  <?php }else{ ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
    <h1 style="text-align:left;">短链接管理</h1>
    <div>
      <a class="btn btn-ghost btn-sm" href="/" style="text-decoration:none;margin-right:6px;">← 首页</a>
      <a class="btn btn-red btn-sm" href="/admin?logout=1" style="text-decoration:none;">退出</a>
    </div>
  </div>
  <?php if(isset($_GET['ok'])){
      $okmap = array('del' => '已删除', 'edit' => '已更新', 'setting' => '访客设置已保存', 'token' => 'API Token 已更新');
      $okmsg = isset($okmap[$_GET['ok']]) ? $okmap[$_GET['ok']] : '已保存';
  ?>
    <div class="ok"><?php echo $okmsg; ?></div>
  <?php } ?>
  <div class="stats">
    <div class="stat"><b><?php echo $total_links; ?></b><span>短链接</span></div>
    <div class="stat"><b><?php echo $total_hits; ?></b><span>总点击</span></div>
    <div class="stat"><b><?php echo $guest_links; ?></b><span>访客创建</span></div>
    <div class="stat"><b><?php echo $guest_today; ?></b><span>今日访客</span></div>
  </div>
  <div class="card">
    <h2 style="font-size:15px;margin-bottom:6px;">访客使用管理</h2>
    <form method="post">
      <input type="hidden" name="action" value="setting">
      <div class="setting-row">
        <div>
          <div class="s-label">允许访客创建短链接</div>
          <div class="s-desc">关闭后仅管理员可创建</div>
        </div>
        <label class="switch">
          <input type="checkbox" name="allow_guest" value="1" <?php echo $allow_guest ? 'checked' : ''; ?>>
          <span class="slider"></span>
        </label>
      </div>
      <div class="setting-row">
        <div>
          <div class="s-label">访客每日创建上限</div>
          <div class="s-desc">按 IP 统计（1-100）</div>
        </div>
        <input type="number" class="num-input" name="guest_limit" min="1" max="100" value="<?php echo $guest_limit; ?>">
      </div>
      <div class="setting-row">
        <div>
          <div class="s-label">访客链接有效期（天）</div>
          <div class="s-desc">到期自动失效删除，0 = 永久（0-365）</div>
        </div>
        <input type="number" class="num-input" name="guest_expire_days" min="0" max="365" value="<?php echo $guest_expire_days; ?>">
      </div>
      <div style="margin-top:12px;"><button class="btn btn-sm" type="submit">保存设置</button></div>
    </form>
  </div>
  <div class="card">
    <div class="search-box">
      <input type="text" id="search" placeholder="🔍 搜索短码或目标链接..." oninput="filterTable()">
    </div>
    <?php if(empty($data['links'])){ ?>
      <div class="empty">暂无短链接，去 <a href="/" style="color:#0d9488">首页</a> 创建一个</div>
    <?php }else{ ?>
    <table id="linkTable">
      <thead><tr><th>短码</th><th>目标链接</th><th width="40">来源</th><th width="56">有效期</th><th width="40">密</th><th width="40">点</th><th width="190">操作</th></tr></thead>
      <tbody>
      <?php foreach($data['links'] as $code => $l){
          $exp_text = '永久';
          $exp_cls = '';
          if (isset($l['expires']) && $l['expires']) {
              $remain = $l['expires'] - time();
              if ($remain <= 0) { $exp_text = '过期'; $exp_cls = ' style="color:#e11d48"'; }
              else { $days = ceil($remain / 86400); $exp_text = $days > 1 ? $days . '天' : ceil($remain / 3600) . '时'; }
          }
          $has_pwd = !empty($l['password']);
      ?>
      <tr>
        <td><span class="code"><?php echo htmlspecialchars($code); ?></span></td>
        <td><span class="url-cell" title="<?php echo htmlspecialchars($l['url']); ?>"><?php echo htmlspecialchars($l['url']); ?></span></td>
        <td title="创建者 IP: <?php echo htmlspecialchars(isset($l['creator_ip']) ? $l['creator_ip'] : '未知'); ?>"><span class="badge <?php echo (isset($l['by']) && $l['by'] === 'guest') ? 'badge-guest' : 'badge-admin'; ?>"><?php echo (isset($l['by']) && $l['by'] === 'guest') ? '客' : '管'; ?></span></td>
        <td><span<?php echo $exp_cls; ?>><?php echo $exp_text; ?></span></td>
        <td><?php echo $has_pwd ? '<span class="lock">🔒</span>' : ''; ?></td>
        <td><?php echo (int)(isset($l['hits']) ? $l['hits'] : 0); ?></td>
        <td class="ops">
          <button class="btn btn-ghost btn-sm" onclick="copyText('<?php echo htmlspecialchars($base . '/' . $code, ENT_QUOTES); ?>', this)">复制</button>
          <button class="btn btn-ghost btn-sm" onclick="showQr('<?php echo htmlspecialchars($base . '/' . $code, ENT_QUOTES); ?>')">码</button>
          <button class="btn btn-ghost btn-sm" onclick="showIps('<?php echo htmlspecialchars($code, ENT_QUOTES); ?>')">IP</button>
          <button class="btn btn-ghost btn-sm" onclick="toggleEdit(this)">编辑</button>
          <form method="post" style="display:inline" onsubmit="return confirm('删除短链接「<?php echo htmlspecialchars($code); ?>」？')">
            <input type="hidden" name="action" value="del">
            <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
            <button class="btn btn-red btn-sm" type="submit">删</button>
          </form>
        </td>
      </tr>
      <tr class="edit-row">
        <td colspan="7">
          <form method="post">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
            <input type="url" name="url" value="<?php echo htmlspecialchars($l['url']); ?>" placeholder="新目标链接">
            <button class="btn btn-sm" type="submit">保存</button>
            <button class="btn btn-ghost btn-sm" type="button" onclick="toggleEdit(this)">取消</button>
          </form>
        </td>
      </tr>
      <?php } ?>
      </tbody>
    </table>
    <?php } ?>
  </div>
  <div class="card">
    <h2 style="font-size:15px;margin-bottom:10px;">API Token 管理</h2>
    <p class="muted" style="margin-bottom:10px;">带 Token 调用 API 将以管理员身份创建短链接（无限额、不自动过期）。Token 比管理密码安全，可单独吊销。</p>
    <?php if(empty($data['api_tokens'])){ ?>
      <div class="empty" style="padding:14px 0;">暂无 Token，点下方按钮生成</div>
    <?php }else{ ?>
    <table>
      <tr><th>Token</th><th width="150">操作</th></tr>
      <?php foreach($data['api_tokens'] as $tk){ ?>
      <tr>
        <td><code style="font-size:11.5px;word-break:break-all;"><?php echo htmlspecialchars($tk); ?></code></td>
        <td class="ops">
          <button class="btn btn-ghost btn-sm" onclick="copyText('<?php echo htmlspecialchars($tk, ENT_QUOTES); ?>', this)">复制</button>
          <form method="post" style="display:inline" onsubmit="return confirm('吊销这个 Token？')">
            <input type="hidden" name="action" value="del_token">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($tk); ?>">
            <button class="btn btn-red btn-sm" type="submit">吊销</button>
          </form>
        </td>
      </tr>
      <?php } ?>
    </table>
    <?php } ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="action" value="gen_token">
      <button class="btn btn-sm" type="submit">生成新 Token</button>
    </form>
  </div>
  <div class="card">
    <h2 style="font-size:15px;margin-bottom:10px;">API 使用</h2>
    <p class="muted" style="line-height:1.9;font-size:12.5px;">
      <b>创建短链接：</b>POST /api/create<br>
      参数：<code>url</code>（必填）、<code>code</code>（可选自定义短码）、<code>password</code>（可选加密）、<code>token</code>（可选，填 API Token 或管理密码则以管理员身份创建）<br>
      示例：<br>
      <code>curl -X POST "https://你的域名/api/create" -d "url=https://blog.example.com" -d "code=blog" -d "token=你的Token"</code>
    </p>
  </div>
  <?php } ?>
<?php }else{ ?>
  <div class="logo">u</div>
  <h1>短链接</h1>
  <p class="sub">把长链接变短 · 一键跳转<?php if(!$is_admin && $allow_guest){ ?> · 访客每日 <?php echo $guest_limit; ?> 个<?php if($guest_expire_days > 0){ ?> · <?php echo $guest_expire_days; ?> 天后自动过期<?php } } ?></p>
  <div class="card">
    <?php if($error){ ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php } ?>
    <form method="post">
      <label>长链接</label>
      <input type="url" name="url" placeholder="https://example.com/very/long/url" autofocus>
      <label>自定义短码（可选，留空自动生成）</label>
      <input type="text" name="code" placeholder="如：my-link">
      <label>访问密码（可选，加密链接）</label>
      <input type="text" name="password" placeholder="留空则无需密码">
      <div style="margin-top:16px;"><button class="btn" type="submit">生成短链接</button></div>
    </form>
    <?php if($result){
        $short_url = $base . '/' . $result;
        $has_pwd = !empty($_POST['password']);
    ?>
    <div class="result">
      <div class="r-label">✅ 短链接已生成<?php echo $has_pwd ? '（已加密 🔒）' : ''; ?></div>
      <div class="r-url" id="newShort"><?php echo htmlspecialchars($short_url); ?></div>
      <div class="r-copy">
        <button class="btn btn-sm" onclick="copyText(document.getElementById('newShort').textContent.trim(), this)">复制链接</button>
        <span class="r-status" id="copyStatus">正在自动复制...</span>
      </div>
      <div class="r-qr">
        <div class="qr-box" id="resultQr"></div>
        <div class="qr-tip">扫一扫访问<br>（可长按保存分享）</div>
      </div>
    </div>
    <script>tryAutoCopy('<?php echo htmlspecialchars($short_url, ENT_QUOTES); ?>');</script>
    <script src="/qrcode.min.js"></script>
    <script>new QRCode(document.getElementById('resultQr'), {text: '<?php echo htmlspecialchars($short_url, ENT_QUOTES); ?>', width: 120, height: 120});</script>
    <?php } ?>
  </div>
  <div class="actions">
    <a href="/admin">管理短链接</a>
    <?php foreach($site_links as $link_name => $link_url){ ?>
    <span style="margin:0 4px;color:#cbd5e1;">·</span>
    <a href="<?php echo htmlspecialchars($link_url); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($link_name); ?></a>
    <?php } ?>
  </div>
<?php } ?>
</div>
<!-- 二维码弹层 -->
<div class="mask" id="qrMask" onclick="if(event.target===this)closeQr()">
  <div class="qr-modal">
    <h3>短链接二维码</h3>
    <div class="qr-wrap" id="qrModalBox"></div>
    <div class="qr-url" id="qrModalUrl"></div>
    <button class="btn btn-sm" onclick="closeQr()">关闭</button>
  </div>
</div>
<!-- IP 访问记录弹层 -->
<div class="mask" id="ipMask" onclick="if(event.target===this)closeIps()">
  <div class="qr-modal" style="max-width:560px;width:94%;">
    <h3>IP 访问记录 <span id="ipMaskCode" class="code" style="font-size:13px;"></span></h3>
    <div id="ipMaskBody" style="max-height:360px;overflow:auto;text-align:left;"></div>
    <button class="btn btn-sm" style="margin-top:12px;" onclick="closeIps()">关闭</button>
  </div>
</div>
<script src="/qrcode.min.js"></script>
<script>
var __ipData = <?php
    $ipd = array();
    foreach ($data['links'] as $c => $l) {
        $ipd[$c] = isset($l['ips']) && is_array($l['ips']) ? $l['ips'] : array();
    }
    echo json_encode($ipd, JSON_UNESCAPED_UNICODE);
?>;
var __ipCache = <?php echo json_encode($data['ip_cache'], JSON_UNESCAPED_UNICODE); ?>;
function copyText(text, btn) {
  var done = function() {
    if (btn) {
      var old = btn.textContent;
      btn.textContent = '✓';
      setTimeout(function() { btn.textContent = old; }, 1200);
    }
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function() { fallbackCopy(text); });
  } else {
    fallbackCopy(text);
  }
}
function fallbackCopy(text) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch(e) {}
  document.body.removeChild(ta);
}
function tryAutoCopy(text) {
  copyText(text, null);
  var st = document.getElementById('copyStatus');
  if (st) setTimeout(function() { st.textContent = '已自动复制到剪贴板'; }, 600);
}
function filterTable() {
  var q = document.getElementById('search').value.toLowerCase();
  var rows = document.querySelectorAll('#linkTable tbody tr');
  rows.forEach(function(row) {
    if (row.classList.contains('edit-row')) return;
    row.style.display = row.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
  });
}
function toggleEdit(btn) {
  var row = btn.closest('tr');
  var editRow = row.nextElementSibling;
  if (editRow && editRow.classList.contains('edit-row')) {
    editRow.style.display = editRow.style.display === 'none' || editRow.style.display === '' ? 'table-row' : 'none';
  }
}
function showQr(url) {
  document.getElementById('qrModalBox').innerHTML = '';
  document.getElementById('qrModalUrl').textContent = url;
  new QRCode(document.getElementById('qrModalBox'), {text: url, width: 180, height: 180});
  document.getElementById('qrMask').classList.add('show');
}
function closeQr() {
  document.getElementById('qrMask').classList.remove('show');
}
var __lookupQueue = {};
function showIps(code) {
  document.getElementById('ipMaskCode').textContent = '/' + code;
  var ips = __ipData[code] || {};
  var keys = Object.keys(ips);
  var body = document.getElementById('ipMaskBody');
  if (keys.length === 0) {
    body.innerHTML = '<div class="empty">暂无访问记录（链接还没有人访问过）</div>';
    document.getElementById('ipMask').classList.add('show');
    return;
  }
  var html = '<table><thead><tr><th>IP</th><th width="46">次数</th><th width="112">最后访问</th><th width="150">归属地</th></tr></thead><tbody>';
  keys.forEach(function(ip) {
    var it = ips[ip];
    var t = new Date(it.last * 1000);
    var ts = (t.getMonth() + 1) + '-' + t.getDate() + ' ' + (t.getHours() < 10 ? '0' : '') + t.getHours() + ':' + (t.getMinutes() < 10 ? '0' : '') + t.getMinutes();
    var label = __ipCache[ip] || '';
    html += '<tr><td style="font-family:ui-monospace,Menlo,monospace;font-size:12px;">' + ip + '</td>'
          + '<td>' + it.n + '</td>'
          + '<td class="muted">' + ts + '</td>'
          + '<td><span class="ip-label" data-ip="' + ip + '">' + (label || '<span class="muted">查询中…</span>') + '</span></td></tr>';
  });
  html += '</tbody></table>';
  body.innerHTML = html;
  document.getElementById('ipMask').classList.add('show');
  // 异步查询未缓存的 IP
  keys.forEach(function(ip) {
    if (__ipCache[ip] || __lookupQueue[ip]) return;
    __lookupQueue[ip] = true;
    fetch('/admin?lookup=1&ip=' + encodeURIComponent(ip))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.code === 200) {
          __ipCache[ip] = d.label;
          var el = document.querySelector('.ip-label[data-ip="' + ip + '"]');
          if (el) el.textContent = d.label;
        } else {
          var el = document.querySelector('.ip-label[data-ip="' + ip + '"]');
          if (el) el.textContent = '未知';
        }
      })
      .catch(function() {
        var el = document.querySelector('.ip-label[data-ip="' + ip + '"]');
        if (el) el.textContent = '查询失败';
      });
  });
}
function closeIps() {
  document.getElementById('ipMask').classList.remove('show');
}
</script>
</body>
</html>
