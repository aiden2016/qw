<?php
session_start();
$maintenanceFile = __DIR__ . DIRECTORY_SEPARATOR . '.maintenance.lock';
$usersFile = __DIR__ . DIRECTORY_SEPARATOR . '.users.json';
$registrationIpFile = __DIR__ . DIRECTORY_SEPARATOR . '.registration-ip.php';
$resourcesFile = __DIR__ . DIRECTORY_SEPARATOR . '.resources.json';
$categoriesFile = __DIR__ . DIRECTORY_SEPARATOR . '.categories.json';
$announcementsFile = __DIR__ . DIRECTORY_SEPARATOR . '.announcements.json';
$passwordResetsFile = __DIR__ . DIRECTORY_SEPARATOR . '.password-resets.php';
$adminPassword = '2016';

$defaultResources = [
  ['id' => 'aiden-notes', 'type' => '文档', 'tag' => '文档 / TXT', 'name' => 'Aiden 笔记', 'description' => '随手记录、配置说明和正在进行中的想法。', 'updated' => '2026.09', 'file' => 'aiden.txt'],
  ['id' => 'archive-71', 'type' => '文档', 'tag' => '文档 / TXT', 'name' => '71 / 资料库', 'description' => '一份轻量的文本资料，适合保存和快速查阅。', 'updated' => '2026.09', 'file' => '71.txt'],
  ['id' => 'hua-mini-site', 'type' => '网页', 'tag' => '网页 / HTML', 'name' => 'HUA / 迷你网站', 'description' => '一个可以直接打开、研究或二次编辑的网页作品。', 'updated' => '2026.07', 'file' => 'hua.html'],
  ['id' => 'hua-experiment', 'type' => 'ai', 'tag' => '实验 / 测试', 'name' => 'HUA / 实验版', 'description' => '一个用于测试新想法、交互和视觉效果的实验版本。', 'updated' => '2026.07', 'file' => 'hua.html']
];

function read_resources(string $file, array $defaults): array
{
  if (!is_file($file)) {
    file_put_contents($file, json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $defaults;
  }
  $resources = json_decode((string) file_get_contents($file), true);
  return is_array($resources) ? $resources : $defaults;
}

function read_categories(string $file, array $defaults): array
{
  if (!is_file($file)) {
    file_put_contents($file, json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $defaults;
  }
  $categories = json_decode((string) file_get_contents($file), true);
  return is_array($categories) ? array_values(array_unique(array_map('strval', $categories))) : $defaults;
}

function read_announcements(string $file): array
{
  if (!is_file($file)) return [];
  $announcements = json_decode((string) file_get_contents($file), true);
  return is_array($announcements) ? $announcements : [];
}

$resources = read_resources($resourcesFile, $defaultResources);
$categories = read_categories($categoriesFile, ['文档', '网页', 'ai']);
$categories = array_values(array_unique(array_map(function ($category) {
  return $category === '实验' ? 'ai' : $category;
}, $categories)));
$announcements = read_announcements($announcementsFile);

function auth_response(bool $success, string $message, ?string $user = null)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => $success, 'message' => $message, 'user' => $user], JSON_UNESCAPED_UNICODE);
  exit;
}

function read_users(string $usersFile): array
{
  if (!is_file($usersFile)) return [];
  $users = json_decode((string) file_get_contents($usersFile), true);
  return is_array($users) ? $users : [];
}

function register_user(string $usersFile, string $registrationIpFile, string $username, string $password, string $ipAddress): array
{
  $handle = fopen($registrationIpFile, 'c+');
  if ($handle === false) return ['success' => false, 'message' => '注册暂时无法完成，请稍后重试。'];
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return ['success' => false, 'message' => '注册暂时无法完成，请稍后重试。'];
  }

  $contents = stream_get_contents($handle);
  if ($contents === '') {
    $records = [];
  } else {
    if (function_exists('opcache_invalidate')) opcache_invalidate($registrationIpFile, true);
    $records = include $registrationIpFile;
  }
  if (!is_array($records)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['success' => false, 'message' => '注册记录读取失败，请联系管理员。'];
  }

  $now = time();
  $cutoff = $now - 86400;
  $records = array_values(array_filter($records, function ($record) use ($cutoff) {
    return is_array($record)
      && isset($record['ip_hash'], $record['created_at'])
      && is_string($record['ip_hash'])
      && is_int($record['created_at'])
      && $record['created_at'] > $cutoff;
  }));
  $ipHash = hash('sha256', $ipAddress);
  $ipRegistrationCount = 0;
  foreach ($records as $record) {
    if (hash_equals($record['ip_hash'], $ipHash)) $ipRegistrationCount++;
  }
  if ($ipRegistrationCount >= 3) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['success' => false, 'message' => '你注册太多了，明天再注册吧'];
  }

  $users = read_users($usersFile);
  if (isset($users[$username])) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['success' => false, 'message' => '这个用户名已经注册。'];
  }

  $registrationId = bin2hex(random_bytes(16));
  $records[] = ['id' => $registrationId, 'ip_hash' => $ipHash, 'created_at' => $now];
  $encodedRecords = '<?php return ' . var_export($records, true) . ';';
  $savedRecords = rewind($handle)
    && ftruncate($handle, 0)
    && fwrite($handle, $encodedRecords) === strlen($encodedRecords)
    && fflush($handle);
  if (!$savedRecords) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['success' => false, 'message' => '注册记录保存失败，请稍后重试。'];
  }

  $users[$username] = password_hash($password, PASSWORD_DEFAULT);
  $encodedUsers = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  $savedUser = $encodedUsers !== false
    && file_put_contents($usersFile, $encodedUsers, LOCK_EX) === strlen($encodedUsers);
  if (!$savedUser) {
    $records = array_values(array_filter($records, function ($record) use ($registrationId) {
      return ($record['id'] ?? '') !== $registrationId;
    }));
    $encodedRecords = '<?php return ' . var_export($records, true) . ';';
    $rolledBack = rewind($handle)
      && ftruncate($handle, 0)
      && fwrite($handle, $encodedRecords) === strlen($encodedRecords)
      && fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return [
      'success' => false,
      'message' => $rolledBack
        ? '账号保存失败，请稍后重试。'
        : '账号保存失败且注册记录回滚失败，请联系管理员。'
    ];
  }

  flock($handle, LOCK_UN);
  fclose($handle);
  return ['success' => true, 'message' => '注册成功，已自动登录。'];
}

$users = read_users($usersFile);
if (isset($_SESSION['user']) && !array_key_exists((string) $_SESSION['user'], $users)) {
  unset($_SESSION['user']);
}

function read_password_resets(string $file): array
{
  if (!is_file($file)) return [];
  if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
  $requests = include $file;
  return is_array($requests) ? $requests : [];
}

function find_password_reset(string $file, string $credential, string $ipAddress): ?array
{
  $parts = explode('.', $credential, 2);
  if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{32}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
    return null;
  }

  foreach (read_password_resets($file) as $request) {
    if (($request['id'] ?? '') === $parts[0]
      && isset($request['token_hash'])
      && hash_equals((string) $request['token_hash'], hash('sha256', $parts[1]))
      && hash_equals((string) ($request['ip'] ?? ''), $ipAddress)) {
      return $request;
    }
  }
  return null;
}

function set_password_reset_cookie(string $value, int $expires): void
{
  setcookie('aiden_password_reset', $value, [
    'expires' => $expires,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict'
  ]);
}

function update_password_resets(string $file, callable $update): bool
{
  $handle = fopen($file, 'c+');
  if ($handle === false) return false;
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }

  $contents = stream_get_contents($handle);
  if ($contents === '') {
    $requests = [];
  } else {
    if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
    $requests = include $file;
  }
  if (!is_array($requests)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    return false;
  }
  $update($requests);
  $encoded = '<?php return ' . var_export($requests, true) . ';';
  $saved = rewind($handle)
    && ftruncate($handle, 0)
    && fwrite($handle, $encoded) === strlen($encoded)
    && fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return $saved;
}

function write_admin_json(string $file, array $data): void
{
  $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($encoded === false || file_put_contents($file, $encoded, LOCK_EX) === false) {
    http_response_code(500);
    exit('后台数据保存失败，请检查服务器文件权限后重试。');
  }
}

if (isset($_GET['download'])) {
  if (is_file($maintenanceFile)) {
    http_response_code(503);
    header('Retry-After: 300');
    exit('网站维护中，暂时无法下载资源。');
  }
  $fileName = basename((string) $_GET['download']);
  $allowedFiles = array_values(array_unique(array_column($resources, 'file')));
  $filePath = __DIR__ . DIRECTORY_SEPARATOR . $fileName;
  if (!isset($_SESSION['user']) || !in_array($fileName, $allowedFiles, true) || !is_file($filePath)) {
    http_response_code(403);
    exit('请先登录后下载资源。');
  }
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . $fileName . '"');
  header('Content-Length: ' . filesize($filePath));
  readfile($filePath);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $adminAction = (string) ($_POST['admin_action'] ?? '');
  if ($adminAction !== '') {
    if ($adminAction === 'login') {
      if (hash_equals($adminPassword, (string) ($_POST['password'] ?? ''))) {
        $_SESSION['admin'] = true;
        header('Location: ?admin=1');
        exit;
      }
      header('Location: ?admin=1&error=1');
      exit;
    }

    if (empty($_SESSION['admin'])) {
      header('Location: ?admin=1&error=1');
      exit;
    }

    if ($adminAction === 'add_user') {
      $csrfToken = (string) ($_POST['csrf_token'] ?? '');
      if (!isset($_SESSION['admin_csrf']) || !hash_equals((string) $_SESSION['admin_csrf'], $csrfToken)) {
        header('Location: ?admin=1&account_create_error=1');
        exit;
      }
      $username = trim((string) ($_POST['username'] ?? ''));
      $password = (string) ($_POST['new_password'] ?? '');
      if (mb_strlen($username) < 2 || mb_strlen($username) > 30 || mb_strlen($password) < 6) {
        header('Location: ?admin=1&account_create_invalid=1');
        exit;
      }
      $users = read_users($usersFile);
      if (array_key_exists($username, $users)) {
        header('Location: ?admin=1&account_create_exists=1');
        exit;
      }
      $users[$username] = password_hash($password, PASSWORD_DEFAULT);
      write_admin_json($usersFile, $users);
      header('Location: ?admin=1&account_created=1');
      exit;
    } elseif ($adminAction === 'password_reset_review') {
      $requestId = (string) ($_POST['request_id'] ?? '');
      $decision = (string) ($_POST['decision'] ?? '');
      $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
      if ($decision === 'rejected' && ($rejectionReason === '' || mb_strlen($rejectionReason) > 500)) {
        header('Location: ?admin=1&reset_reason_error=1');
        exit;
      }
      if (in_array($decision, ['approved', 'rejected'], true)) {
        $saved = update_password_resets($passwordResetsFile, function (&$requests) use ($requestId, $decision, $rejectionReason) {
          foreach ($requests as &$request) {
            if (($request['id'] ?? '') === $requestId && ($request['status'] ?? '') === 'pending') {
              $request['status'] = $decision;
              $request['reviewed_at'] = date('c');
              if ($decision === 'rejected') $request['rejection_reason'] = $rejectionReason;
              break;
            }
          }
          unset($request);
        });
        if (!$saved) {
          header('Location: ?admin=1&reset_error=1');
          exit;
        }
      }
    } elseif ($adminAction === 'add') {
      $name = trim((string) ($_POST['name'] ?? ''));
      $file = basename(trim((string) ($_POST['file'] ?? '')));
      $type = trim((string) ($_POST['type'] ?? '文档'));
      $description = trim((string) ($_POST['description'] ?? ''));
      if ($name !== '' && $file !== '' && $description !== '') {
        $tag = $type === '网页' ? '网页 / HTML' : ($type === 'ai' ? '实验 / 测试' : ($type === '文档' ? '文档 / TXT' : $type . ' / 资源'));
        $resources[] = ['id' => uniqid('resource-', true), 'type' => $type, 'tag' => $tag, 'name' => $name, 'description' => $description, 'updated' => date('Y.m'), 'file' => $file];
        write_admin_json($resourcesFile, $resources);
      }
    } elseif ($adminAction === 'add_category') {
      $category = trim((string) ($_POST['category'] ?? ''));
      if ($category !== '' && mb_strlen($category) <= 20 && !in_array($category, $categories, true)) {
        $categories[] = $category;
        write_admin_json($categoriesFile, $categories);
      }
    } elseif ($adminAction === 'add_announcement') {
      $title = trim((string) ($_POST['announcement_title'] ?? ''));
      $content = trim((string) ($_POST['announcement_content'] ?? ''));
      if ($title !== '' && $content !== '') {
        array_unshift($announcements, ['id' => uniqid('announcement-', true), 'title' => $title, 'content' => $content, 'date' => date('Y.m.d')]);
        write_admin_json($announcementsFile, $announcements);
      }
    } elseif ($adminAction === 'delete_announcement') {
      $announcementId = (string) ($_POST['announcement_id'] ?? '');
      $announcements = array_values(array_filter($announcements, function ($announcement) use ($announcementId) {
        return ($announcement['id'] ?? '') !== $announcementId;
      }));
      write_admin_json($announcementsFile, $announcements);
    } elseif ($adminAction === 'delete_category' || $adminAction === 'bulk_delete_category') {
      $selectedCategories = $adminAction === 'bulk_delete_category'
        ? (array) ($_POST['categories'] ?? [])
        : [(string) ($_POST['category'] ?? '')];
      if (!empty($_POST['single_category'])) $selectedCategories = [(string) $_POST['single_category']];
      $selectedCategories = array_map('strval', $selectedCategories);
      $deletable = array_values(array_intersect($categories, $selectedCategories));
      $remainingCategories = array_values(array_diff($categories, $deletable));
      if ($deletable !== []) {
        $fallbackCategory = $remainingCategories[0] ?? '未分类';
        foreach ($resources as &$resource) {
          if (in_array((string) ($resource['type'] ?? ''), $deletable, true)) {
            $resource['type'] = $fallbackCategory;
            $resource['tag'] = $fallbackCategory === '网页' ? '网页 / HTML' : ($fallbackCategory === 'ai' ? '实验 / 测试' : ($fallbackCategory === '文档' ? '文档 / TXT' : $fallbackCategory . ' / 资源'));
          }
        }
        unset($resource);
        $categories = $remainingCategories;
        write_admin_json($resourcesFile, $resources);
        write_admin_json($categoriesFile, $categories);
      }
    } elseif ($adminAction === 'delete' || $adminAction === 'bulk_delete') {
      $ids = $adminAction === 'bulk_delete'
        ? (array) ($_POST['ids'] ?? [])
        : [(string) ($_POST['id'] ?? '')];
      if (!empty($_POST['single_id'])) $ids = [(string) $_POST['single_id']];
      $ids = array_map('strval', $ids);
      $resources = array_values(array_filter($resources, function ($resource) use ($ids) {
        return !in_array((string) ($resource['id'] ?? ''), $ids, true);
      }));
      write_admin_json($resourcesFile, $resources);
    } elseif ($adminAction === 'change_user_password') {
      $csrfToken = (string) ($_POST['csrf_token'] ?? '');
      if (!isset($_SESSION['admin_csrf']) || !hash_equals((string) $_SESSION['admin_csrf'], $csrfToken)) {
        header('Location: ?admin=1&account_password_error=1');
        exit;
      }
      $username = trim((string) ($_POST['username'] ?? ''));
      $newPassword = (string) ($_POST['new_password'] ?? '');
      if (mb_strlen($newPassword) < 6) {
        header('Location: ?admin=1&account_password_invalid=1');
        exit;
      }
      $users = read_users($usersFile);
      if (!array_key_exists($username, $users)) {
        header('Location: ?admin=1&account_missing=1');
        exit;
      }
      $resetsRemoved = update_password_resets($passwordResetsFile, function (&$requests) use ($username) {
        $requests = array_values(array_filter($requests, function ($request) use ($username) {
          return !is_array($request) || (string) ($request['username'] ?? '') !== $username;
        }));
      });
      if (!$resetsRemoved) {
        header('Location: ?admin=1&account_password_error=1');
        exit;
      }
      $users[$username] = password_hash($newPassword, PASSWORD_DEFAULT);
      write_admin_json($usersFile, $users);
      header('Location: ?admin=1&account_password_changed=1');
      exit;
    } elseif ($adminAction === 'delete_user') {
      $csrfToken = (string) ($_POST['csrf_token'] ?? '');
      if (!isset($_SESSION['admin_csrf']) || !hash_equals((string) $_SESSION['admin_csrf'], $csrfToken)) {
        header('Location: ?admin=1&account_delete_error=1');
        exit;
      }
      $username = trim((string) ($_POST['username'] ?? ''));
      $users = read_users($usersFile);
      if (!array_key_exists($username, $users)) {
        header('Location: ?admin=1&account_missing=1');
        exit;
      }
      $resetsRemoved = update_password_resets($passwordResetsFile, function (&$requests) use ($username) {
        $requests = array_values(array_filter($requests, function ($request) use ($username) {
          return !is_array($request) || (string) ($request['username'] ?? '') !== $username;
        }));
      });
      if (!$resetsRemoved) {
        header('Location: ?admin=1&account_delete_error=1');
        exit;
      }
      unset($users[$username]);
      write_admin_json($usersFile, $users);
      if (isset($_SESSION['user']) && (string) $_SESSION['user'] === $username) {
        unset($_SESSION['user']);
      }
      header('Location: ?admin=1&account_deleted=1');
      exit;
    } elseif ($adminAction === 'bulk_delete_users') {
      $csrfToken = (string) ($_POST['csrf_token'] ?? '');
      if (!isset($_SESSION['admin_csrf']) || !hash_equals((string) $_SESSION['admin_csrf'], $csrfToken)) {
        header('Location: ?admin=1&account_delete_error=1');
        exit;
      }
      $selectedUsernames = $_POST['usernames'] ?? [];
      if (!is_array($selectedUsernames)) {
        header('Location: ?admin=1&account_delete_error=1');
        exit;
      }
      $selectedUsernames = array_values(array_unique(array_filter(array_map(function ($username) {
        return is_string($username) ? trim($username) : '';
      }, $selectedUsernames), function ($username) {
        return $username !== '';
      })));
      if ($selectedUsernames === []) {
        header('Location: ?admin=1&account_delete_error=1');
        exit;
      }

      $users = read_users($usersFile);
      $usernamesToDelete = array_values(array_filter($selectedUsernames, function ($username) use ($users) {
        return array_key_exists($username, $users);
      }));
      if ($usernamesToDelete === []) {
        header('Location: ?admin=1&account_missing=1');
        exit;
      }
      $resetsRemoved = update_password_resets($passwordResetsFile, function (&$requests) use ($usernamesToDelete) {
        $requests = array_values(array_filter($requests, function ($request) use ($usernamesToDelete) {
          return !is_array($request) || !in_array((string) ($request['username'] ?? ''), $usernamesToDelete, true);
        }));
      });
      if (!$resetsRemoved) {
        header('Location: ?admin=1&account_delete_error=1');
        exit;
      }
      foreach ($usernamesToDelete as $username) {
        unset($users[$username]);
      }
      write_admin_json($usersFile, $users);
      if (isset($_SESSION['user']) && in_array((string) $_SESSION['user'], $usernamesToDelete, true)) {
        unset($_SESSION['user']);
      }
      header('Location: ?admin=1&account_bulk_deleted=' . count($usernamesToDelete));
      exit;
    } elseif ($adminAction === 'logout') {
      unset($_SESSION['admin']);
      unset($_SESSION['admin_csrf']);
      header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
      exit;
    }
    header('Location: ?admin=1');
    exit;
  }
  $authAction = $_POST['auth_action'] ?? '';
  if ($authAction === 'password_reset_request') {
    $username = trim((string) ($_POST['username'] ?? ''));
    if (mb_strlen($username) < 2 || mb_strlen($username) > 30) {
      auth_response(false, '请输入有效的用户名。');
    }

    $users = read_users($usersFile);
    $genericMessage = '如果该用户名已注册，申请已提交后台审核；审核结果会显示在此页面。';
    if (isset($users[$username])) {
      $requestId = bin2hex(random_bytes(16));
      $token = bin2hex(random_bytes(32));
      $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
      $saved = update_password_resets($passwordResetsFile, function (&$requests) use ($requestId, $username, $ipAddress, $token) {
        $requests[] = [
          'id' => $requestId,
          'username' => $username,
          'ip' => $ipAddress,
          'token_hash' => hash('sha256', $token),
          'status' => 'pending',
          'created_at' => date('c')
        ];
      });
      if (!$saved) auth_response(false, '申请暂时无法提交，请稍后重试。');
      $_SESSION['password_reset'] = ['id' => $requestId, 'token' => $token, 'ip' => $ipAddress];
      set_password_reset_cookie($requestId . '.' . $token, time() + 60 * 60 * 24 * 30);
    } else {
      unset($_SESSION['password_reset']);
      set_password_reset_cookie('', time() - 3600);
    }
    auth_response(true, $genericMessage);
  }
  if ($authAction === 'password_reset_complete') {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    if (mb_strlen($newPassword) < 6) auth_response(false, '新密码至少需要 6 位。');
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $credential = (string) ($_COOKIE['aiden_password_reset'] ?? '');
    $matchedRequest = find_password_reset($passwordResetsFile, $credential, $ipAddress);
    if ($matchedRequest === null) {
      auth_response(false, '重置凭证无效或当前 IP 已变化或账号不存在。');
    }
    if (($matchedRequest['status'] ?? '') !== 'approved') {
      auth_response(false, '申请尚未获批或已失效。');
    }

    $users = read_users($usersFile);
    $username = (string) ($matchedRequest['username'] ?? '');
    if (!isset($users[$username])) auth_response(false, '账户不存在，无法重置密码。');
    $users[$username] = password_hash($newPassword, PASSWORD_DEFAULT);
    if (file_put_contents($usersFile, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
      auth_response(false, '密码保存失败，请稍后重试。');
    }
    $saved = update_password_resets($passwordResetsFile, function (&$requests) use ($matchedRequest) {
      foreach ($requests as &$request) {
        if (($request['id'] ?? '') === $matchedRequest['id'] && ($request['status'] ?? '') === 'approved') {
          $request['status'] = 'completed';
          $request['completed_at'] = date('c');
          break;
        }
      }
      unset($request);
    });
    if (!$saved) auth_response(false, '密码已更新，但申请状态保存失败，请联系管理员。');
    unset($_SESSION['password_reset']);
    set_password_reset_cookie('', time() - 3600);
    auth_response(true, '密码已重置，请使用新密码登录。');
  }
  if ($authAction === 'register') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (mb_strlen($username) < 2 || mb_strlen($username) > 30 || mb_strlen($password) < 6) {
      auth_response(false, '用户名至少 2 个字符，密码至少 6 位。');
    }
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $registration = register_user($usersFile, $registrationIpFile, $username, $password, $ipAddress);
    if (!$registration['success']) auth_response(false, $registration['message']);
    $_SESSION['user'] = $username;
    auth_response(true, $registration['message'], $username);
  }
  if ($authAction === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $users = read_users($usersFile);
    if (!isset($users[$username]) || !password_verify($password, $users[$username])) {
      auth_response(false, '用户名或密码错误。');
    }
    $_SESSION['user'] = $username;
    auth_response(true, '登录成功。', $username);
  }
  if ($authAction === 'logout') {
    unset($_SESSION['user']);
    auth_response(true, '已退出登录。');
  }
  $action = $_POST['maintenance_action'] ?? '';
  $password = $_POST['password'] ?? '';

  if (hash_equals($adminPassword, $password) && in_array($action, ['lock', 'unlock'], true)) {
    if ($action === 'lock') {
      file_put_contents($maintenanceFile, date('c'), LOCK_EX);
    } else {
      @unlink($maintenanceFile);
    }
  }

  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

$serverMaintenanceMode = is_file($maintenanceFile);
$passwordResetRequest = find_password_reset($passwordResetsFile, (string) ($_COOKIE['aiden_password_reset'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$approvedPasswordReset = $passwordResetRequest !== null && ($passwordResetRequest['status'] ?? '') === 'approved';
$rejectedPasswordReset = $passwordResetRequest !== null && ($passwordResetRequest['status'] ?? '') === 'rejected';

if (isset($_GET['maintenance-check'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  echo json_encode(['maintenance' => $serverMaintenanceMode], JSON_UNESCAPED_UNICODE);
  exit;
}
if (isset($_GET['password-reset-status'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  $request = find_password_reset($passwordResetsFile, (string) ($_COOKIE['aiden_password_reset'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
  $status = $request['status'] ?? 'unavailable';
  echo json_encode([
    'status' => $status,
    'request_id' => (string) ($request['id'] ?? ''),
    'reason' => $status === 'rejected' ? (string) ($request['rejection_reason'] ?? '') : ''
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate');
if (isset($_GET['admin'])):
$loggedIn = !empty($_SESSION['admin']);
if ($loggedIn && empty($_SESSION['admin_csrf'])) {
  $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$pendingPasswordResets = $loggedIn
  ? array_values(array_filter(read_password_resets($passwordResetsFile), function ($request) {
    return ($request['status'] ?? '') === 'pending';
  }))
  : [];
$users = $loggedIn ? read_users($usersFile) : [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>网站后台 / AIDEN</title>
  <style>
    :root { color-scheme: dark; --ink: #f3f5ef; --muted: #9ba59d; --line: rgba(243,245,239,.18); --acid: #d7ff45; --orange: #ff714b; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; color: var(--ink); background: linear-gradient(135deg,#07111f,#12345a); font: 14px/1.6 'Segoe UI','Microsoft YaHei',sans-serif; }
    a { color: #8de8ed; text-decoration: none; }
    button,input,textarea,select { font: inherit; }
    .shell { width: min(940px,calc(100% - 32px)); margin: 0 auto; }
    header { display:flex; justify-content:space-between; align-items:center; padding:22px 0; border-bottom:1px solid var(--line); }
    header strong { font-size:18px; letter-spacing:.04em; }
    main { padding:34px 0 80px; }
    h1 { margin:0; font-size:clamp(34px,7vw,58px); line-height:1.1; }
    .login h1 { margin-bottom:18px; font-size:24px; }
    h2 { margin:0 0 16px; font-size:22px; }
    .intro,.hint { color:var(--muted); }
    .panel { margin-top:22px; padding:24px; border:1px solid var(--line); background:rgba(8,19,33,.66); box-shadow:7px 8px 0 rgba(1,8,17,.4); }
    .login { width:min(100%,410px); margin:12vh auto; }
    .form { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .form input,.form textarea,.form select { width:100%; padding:11px 13px; border:1px solid var(--line); background:rgba(255,255,255,.07); color:var(--ink); }
    .form select option { background:#102947; }
    .form textarea { min-height:82px; grid-column:1/-1; resize:vertical; }
    .form button,.primary { padding:10px 15px; border:0; background:var(--acid); color:#07111e; font-weight:700; cursor:pointer; }
    .form button:hover,.primary:hover { background:var(--orange); }
    .admin-row,.reset-row { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:14px 0; border-bottom:1px solid var(--line); }
    .admin-row small,.reset-row small,.account-row small { display:block; color:var(--muted); }
    .account-row { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:14px 0; border-bottom:1px solid var(--line); }
    .account-actions,.account-actions form { display:flex; align-items:center; gap:8px; }
    .account-actions input { width:min(190px,32vw); padding:8px 10px; border:1px solid var(--line); background:rgba(255,255,255,.07); color:var(--ink); }
    .admin-info { display:flex; align-items:center; gap:12px; }
    .admin-info input[type=checkbox],.bulk-bar input[type=checkbox] { accent-color:var(--acid); }
    .delete,.reject { padding:7px 11px; border:1px solid var(--orange); background:transparent; color:var(--orange); cursor:pointer; }
    .delete:hover,.reject:hover { background:var(--orange); color:#07111e; }
    .bulk-bar { display:flex; justify-content:space-between; align-items:center; padding:8px 0 12px; color:var(--muted); }
    .category-list { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:16px; }
    .category-row { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:10px; border:1px solid var(--line); }
    .actions { display:flex; gap:8px; }
    .actions form { margin:0; }
    .actions form:last-child { display:grid; gap:8px; }
    .actions textarea { display:block; width:min(240px,55vw); margin-bottom:8px; padding:8px; border:1px solid var(--line); background:rgba(255,255,255,.07); color:var(--ink); resize:vertical; }
    .admin-page-actions { display:flex; justify-content:flex-end; align-items:center; gap:10px; }
    .admin-page-actions a { padding:7px 11px; border:1px solid var(--acid); color:var(--acid); }
    .admin-page-actions a:hover { background:var(--acid); color:#07111e; }
    .error { color:var(--orange); }
    @media(max-width:600px) { .panel { padding:18px; } .form,.category-list { grid-template-columns:1fr; } .admin-row,.reset-row { align-items:flex-start; } .actions { flex-direction:column; } .account-row,.account-actions { align-items:flex-start; flex-direction:column; } .account-actions input { width:100%; } .account-actions form:first-child { flex-wrap:wrap; } }
  </style>
</head>
<body>
  <header class="shell">
    <strong>AIDEN / 网站后台</strong>
    <a href="app.php">返回主页</a>
  </header>
  <main class="shell">
    <?php if (!$loggedIn): ?>
      <section class="panel login">
        <h1>管理员登录</h1>
        <?php if (isset($_GET['error'])): ?><p class="error">密码错误，请重试。</p><?php endif; ?>
        <form class="form" method="post" action="?admin=1">
          <input type="hidden" name="admin_action" value="login">
          <input name="password" type="password" placeholder="管理员密码" autocomplete="current-password" required>
          <button type="submit">登录后台</button>
        </form>
      </section>
    <?php else: ?>
      <h1>网站后台</h1>
      <p class="intro">管理资源、公告、分类和密码重置申请。</p>
      <div class="admin-page-actions">
        <a href="app.php?admin=1">刷新</a>
        <form method="post" action="?admin=1"><button class="delete" name="admin_action" value="logout">退出后台</button></form>
      </div>

      <section class="panel">
        <h2>忘记密码申请（<?= count($pendingPasswordResets) ?>）</h2>
        <?php if (isset($_GET['reset_error'])): ?><p class="error">申请状态保存失败，请重试。</p><?php endif; ?>
        <?php if (isset($_GET['reset_reason_error'])): ?><p class="error">拒绝申请时必须填写原因，最多 500 个字符。</p><?php endif; ?>
        <?php if ($pendingPasswordResets === []): ?>
          <p class="hint">当前没有待审核申请。</p>
        <?php else: foreach ($pendingPasswordResets as $request): ?>
          <div class="reset-row">
            <div>
              <strong><?= htmlspecialchars((string) ($request['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
              <small>申请 IP：<?= htmlspecialchars((string) ($request['ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
              <small><?= htmlspecialchars((string) ($request['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <div class="actions">
              <form method="post" action="?admin=1" onsubmit="return confirm('同意后，申请人可在原浏览器和 IP 下自行设置新密码。继续吗？');">
                <input type="hidden" name="admin_action" value="password_reset_review">
                <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="decision" value="approved">
                <button class="primary" type="submit">同意</button>
              </form>
              <form method="post" action="?admin=1" onsubmit="return confirm('确定拒绝这条申请吗？');">
                <input type="hidden" name="admin_action" value="password_reset_review">
                <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="decision" value="rejected">
                <textarea name="rejection_reason" rows="2" maxlength="500" placeholder="填写拒绝原因（必填）" required></textarea>
                <button class="reject" type="submit">拒绝</button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </section>

      <section class="panel">
        <h2>账号管理（<?= count($users) ?>）</h2>
        <p class="hint">账号密码以不可逆哈希保存，无法查看原密码；管理员可以在此为用户设置新密码（至少 6 位）。</p>
        <?php if (isset($_GET['account_created'])): ?><p>账号已创建，可以在前台登录。</p><?php endif; ?>
        <?php if (isset($_GET['account_create_invalid'])): ?><p class="error">用户名需为 2–30 个字符，密码至少 6 位。</p><?php endif; ?>
        <?php if (isset($_GET['account_create_exists'])): ?><p class="error">该用户名已存在。</p><?php endif; ?>
        <?php if (isset($_GET['account_create_error'])): ?><p class="error">创建账号请求无效，请刷新后台后重试。</p><?php endif; ?>
        <?php if (isset($_GET['account_deleted'])): ?><p>账号已注销。</p><?php endif; ?>
        <?php if (isset($_GET['account_bulk_deleted'])): ?><p>已批量注销 <?= (int) $_GET['account_bulk_deleted'] ?> 个账号。</p><?php endif; ?>
        <?php if (isset($_GET['account_missing'])): ?><p class="error">账号不存在或已被注销。</p><?php endif; ?>
        <?php if (isset($_GET['account_delete_error'])): ?><p class="error">注销请求无效，请刷新后台后重试。</p><?php endif; ?>
        <?php if (isset($_GET['account_password_changed'])): ?><p>账号密码已更新。</p><?php endif; ?>
        <?php if (isset($_GET['account_password_invalid'])): ?><p class="error">新密码至少需要 6 位。</p><?php endif; ?>
        <?php if (isset($_GET['account_password_error'])): ?><p class="error">密码更新请求无效或申请状态保存失败，请刷新后台后重试。</p><?php endif; ?>
        <form class="form" method="post" action="?admin=1">
          <input type="hidden" name="admin_action" value="add_user">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>">
          <input name="username" minlength="2" maxlength="30" placeholder="新账号名（2–30 个字符）" autocomplete="username" required>
          <input name="new_password" type="password" minlength="6" placeholder="初始密码（至少 6 位）" autocomplete="new-password" required>
          <button type="submit">创建账号</button>
        </form>
        <?php if ($users !== []): ?>
          <form id="bulkDeleteUsersForm" method="post" action="?admin=1" onsubmit="return confirm('确定注销选中的账号吗？此操作不可恢复。');">
            <input type="hidden" name="admin_action" value="bulk_delete_users">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="bulk-bar">
              <label><input type="checkbox" id="selectAllUsers"> 全选账号</label>
              <button class="delete" type="submit">批量注销</button>
            </div>
          </form>
        <?php endif; ?>
        <?php if ($users === []): ?>
          <p class="hint">当前没有注册账号。</p>
        <?php else: foreach ($users as $username => $passwordHash): ?>
          <div class="account-row">
            <div class="admin-info">
              <label><input type="checkbox" name="usernames[]" value="<?= htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8') ?>" form="bulkDeleteUsersForm" class="user-checkbox" aria-label="选择账号 <?= htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8') ?>"></label>
              <strong><?= htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="account-actions">
              <form method="post" action="?admin=1" onsubmit="return confirm('确定为此账号设置新密码吗？');">
                <input type="hidden" name="admin_action" value="change_user_password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="username" value="<?= htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8') ?>">
                <input name="new_password" type="password" minlength="6" placeholder="输入新密码" autocomplete="new-password" required>
                <button class="primary" type="submit">更改密码</button>
              </form>
              <form method="post" action="?admin=1" onsubmit="return confirm('确定注销此用户账号吗？此操作不可恢复。');">
                <input type="hidden" name="admin_action" value="delete_user">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="username" value="<?= htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8') ?>">
                <button class="delete" type="submit">注销账号</button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </section>

      <section class="panel">
        <h2>添加资源</h2>
        <form class="form" method="post" action="?admin=1">
          <input type="hidden" name="admin_action" value="add">
          <input name="name" placeholder="资源名称" required>
          <input name="file" placeholder="文件名，例如 new.txt" required>
          <select name="type"><?php if ($categories === []): ?><option value="未分类">未分类</option><?php else: foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category === 'ai' ? '实验' : $category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; endif; ?></select>
          <textarea name="description" placeholder="资源描述" required></textarea>
          <button type="submit">保存资源</button>
        </form>
        <form method="post" action="?admin=1" onsubmit="return confirm('确定删除选中的资源吗？');">
          <input type="hidden" name="admin_action" value="bulk_delete">
          <div class="bulk-bar"><label><input type="checkbox" id="selectAllResources"> 全选资源</label><button class="delete" type="submit">批量删除</button></div>
          <?php foreach ($resources as $resource): ?>
            <div class="admin-row">
              <label class="admin-info"><input type="checkbox" name="ids[]" value="<?= htmlspecialchars((string) ($resource['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="resource-checkbox"><span><strong><?= htmlspecialchars((string) ($resource['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) ($resource['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($resource['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></span></label>
              <button class="delete" type="submit" name="single_id" value="<?= htmlspecialchars((string) ($resource['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" formnovalidate onclick="return confirm('确定删除这个资源吗？');">删除</button>
            </div>
          <?php endforeach; ?>
        </form>
      </section>

      <section class="panel">
        <h2>发布公告</h2>
        <form class="form" method="post" action="?admin=1">
          <input type="hidden" name="admin_action" value="add_announcement">
          <input name="announcement_title" placeholder="公告标题" required>
          <textarea name="announcement_content" placeholder="公告内容" required></textarea>
          <button type="submit">发布公告</button>
        </form>
        <?php foreach ($announcements as $announcement): ?>
          <div class="admin-row">
            <div><strong><?= htmlspecialchars((string) ($announcement['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><small><?= nl2br(htmlspecialchars((string) ($announcement['content'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></small><small><?= htmlspecialchars((string) ($announcement['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div>
            <form method="post" action="?admin=1" onsubmit="return confirm('确定删除这条公告吗？');">
              <input type="hidden" name="admin_action" value="delete_announcement"><input type="hidden" name="announcement_id" value="<?= htmlspecialchars((string) ($announcement['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
              <button class="delete" type="submit">删除</button>
            </form>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel">
        <h2>分类管理（<?= count($categories) ?>）</h2>
        <form class="form" method="post" action="?admin=1">
          <input type="hidden" name="admin_action" value="add_category"><input name="category" placeholder="新分类名称" required><button type="submit">添加分类</button>
        </form>
        <form method="post" action="?admin=1" onsubmit="return confirm('删除分类后，相关资源将归入未分类。继续吗？');">
          <input type="hidden" name="admin_action" value="bulk_delete_category">
          <div class="bulk-bar"><label><input type="checkbox" id="selectAllCategories"> 全选分类</label><button class="delete" type="submit">批量删除</button></div>
          <div class="category-list">
            <?php foreach ($categories as $category): ?>
              <div class="category-row">
                <label><input type="checkbox" name="categories[]" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" class="category-checkbox"> <?= htmlspecialchars($category === 'ai' ? '实验' : $category, ENT_QUOTES, 'UTF-8') ?></label>
                <button class="delete" type="submit" name="single_category" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" formnovalidate onclick="return confirm('删除此分类后，相关资源将归入未分类。继续吗？');">删除</button>
              </div>
            <?php endforeach; ?>
          </div>
        </form>
      </section>
    <?php endif; ?>
  </main>
  <script>
    for (const [selectId, checkboxClass] of [['selectAllResources', 'resource-checkbox'], ['selectAllCategories', 'category-checkbox'], ['selectAllUsers', 'user-checkbox']]) {
      const selectAll = document.querySelector(`#${selectId}`);
      if (!selectAll) continue;
      const checkboxes = [...document.querySelectorAll(`.${checkboxClass}`)];
      selectAll.addEventListener('change', () => checkboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; }));
      checkboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
        selectAll.checked = checkboxes.length > 0 && checkboxes.every((item) => item.checked);
      }));
    }
  </script>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Aiden 的官方资源中心，下载最新工具、文档和实验作品。">
  <title>AIDEN / 官方资源中心</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@400;500;600;700&display=swap');

    :root {
      --ink: #f3f5ef;
      --paper: #0d1d31;
      --muted: #9ba59d;
      --line: rgba(243, 245, 239, 0.18);
      --acid: #d7ff45;
      --cyan: #8de8ed;
      --orange: #ff714b;
      --glass: rgba(8, 19, 33, .58);
      --glass-light: rgba(255, 255, 255, .09);
    }

    * { box-sizing: border-box; }

    ::selection { background: var(--orange); color: var(--ink); }

    html { scroll-behavior: smooth; }

    @keyframes rise-in {
      from { opacity: 0; transform: translateY(22px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes float-orbit {
      0%, 100% { transform: rotate(12deg) translateY(0); }
      50% { transform: rotate(12deg) translateY(-9px); }
    }

    @keyframes blink { 50% { opacity: .35; } }

    @keyframes grid-drift {
      from { background-position: 0 0, 0 0; }
      to { background-position: 42px 42px, 42px 42px; }
    }

    @keyframes orbit-spin { to { transform: rotate(360deg); } }

    @keyframes accent-breathe {
      0%, 100% { color: var(--orange); text-shadow: 0 0 0 rgba(255, 113, 75, 0); }
      50% { color: #ff9a43; text-shadow: 5px 5px 0 rgba(255, 113, 75, .18); }
    }

    @keyframes mark-bounce {
      0%, 100% { transform: rotate(-8deg) translateY(0); }
      50% { transform: rotate(-8deg) translateY(-4px); }
    }

    @keyframes scan-line {
      from { transform: translateX(-110%); }
      to { transform: translateX(110%); }
    }

    @keyframes footer-rise {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes gradient-flow {
      0%, 100% { background-position: 85% 8%, 8% 65%, 0% 50%; }
      50% { background-position: 72% 18%, 20% 55%, 100% 50%; }
    }

    @keyframes aurora-drift {
      0%, 100% { opacity: .28; transform: translate3d(-4%, -2%, 0) rotate(-3deg) scale(1); }
      50% { opacity: .48; transform: translate3d(5%, 3%, 0) rotate(3deg) scale(1.08); }
    }

    @keyframes count-pop {
      0% { transform: scale(1); }
      45% { color: var(--orange); transform: scale(1.12); }
      100% { transform: scale(1); }
    }

    @keyframes card-pop {
      from { opacity: 0; transform: translateY(18px) scale(.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    @keyframes scan-drift {
      from { transform: translateY(-30vh); opacity: 0; }
      20%, 80% { opacity: .45; }
      to { transform: translateY(130vh); opacity: 0; }
    }

    @keyframes signal-pulse {
      0%, 100% { opacity: .25; transform: scale(.8); }
      50% { opacity: 1; transform: scale(1.25); }
    }

    @keyframes rail-glow {
      0%, 100% { opacity: .18; }
      50% { opacity: .5; }
    }

    body {
      margin: 0;
      color: var(--ink);
      background:
        radial-gradient(circle at 85% 8%, rgba(45, 137, 196, .46), transparent 25rem),
        radial-gradient(circle at 8% 65%, rgba(37, 96, 164, .42), transparent 24rem),
        linear-gradient(125deg, #09172b 0%, #153861 48%, #1b5385 100%);
      background-size: auto, auto, 180% 180%;
      font-family: 'DM Sans', 'PingFang SC', sans-serif;
      overflow-x: hidden;
      animation: gradient-flow 18s ease-in-out infinite;
    }

    body::before {
      position: fixed;
      inset: 0;
      z-index: -1;
      background-image: linear-gradient(rgba(243, 245, 239, .055) 1px, transparent 1px), linear-gradient(90deg, rgba(243, 245, 239, .055) 1px, transparent 1px);
      background-size: 42px 42px;
      content: '';
      pointer-events: none;
      animation: grid-drift 18s linear infinite;
    }

    body::after {
      position: fixed;
      inset: -25%;
      z-index: -1;
      background: radial-gradient(ellipse at 50% 50%, rgba(93, 188, 255, .16), transparent 38%), radial-gradient(ellipse at 25% 75%, rgba(52, 102, 208, .2), transparent 34%);
      content: '';
      pointer-events: none;
      animation: aurora-drift 14s ease-in-out infinite;
    }

    body::after { box-shadow: 0 -35vh 18vh -24vh rgba(141, 232, 237, .3) inset; }

    .background-system { position: fixed; inset: 0; z-index: -1; overflow: hidden; color: rgba(243, 245, 239, .38); pointer-events: none; }
    .background-system::before { position: absolute; top: -20%; right: 13%; width: 1px; height: 140%; background: linear-gradient(transparent, rgba(141, 232, 237, .5), transparent); content: ''; animation: rail-glow 5s ease-in-out infinite; }
    .background-system::after { position: absolute; top: -10%; left: 0; width: 100%; height: 1px; background: linear-gradient(90deg, transparent, var(--acid), transparent); content: ''; animation: scan-drift 11s linear infinite; }
    .background-label { position: absolute; font: 10px/1 'Space Grotesk', sans-serif; letter-spacing: .16em; text-transform: uppercase; opacity: .5; }
    .background-label.top { top: 112px; right: 6%; }
    .background-label.bottom { bottom: 42px; left: 5%; }
    .background-corner { position: absolute; width: 58px; height: 58px; border: 1px solid rgba(141, 232, 237, .25); }
    .background-corner::after { position: absolute; right: -4px; bottom: -4px; width: 7px; height: 7px; border-radius: 50%; background: var(--cyan); box-shadow: 0 0 12px var(--cyan); content: ''; animation: signal-pulse 2.8s ease-in-out infinite; }
    .background-corner.left { bottom: 12%; left: 2%; border-right: 0; border-top: 0; }
    .background-corner.right { top: 20%; right: 2%; border-bottom: 0; border-left: 0; }

    a { color: inherit; text-decoration: none; }

    .shell { width: min(1180px, calc(100% - 40px)); margin: 0 auto; }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 84px;
      border-bottom: 1px solid var(--line);
      position: sticky;
      top: 0;
      z-index: 10;
      background: rgba(9, 23, 43, .72);
      backdrop-filter: blur(18px);
      animation: rise-in .6s ease both;
    }

    .brand { display: flex; gap: 12px; align-items: center; font-family: 'Space Grotesk', sans-serif; font-weight: 700; letter-spacing: -.04em; animation: rise-in .6s ease both; }
    .brand-mark { display: grid; width: 34px; height: 34px; place-items: center; color: var(--paper); background: var(--ink); font-size: 13px; transform: rotate(-8deg); transition: background .25s ease, color .25s ease, box-shadow .25s ease; }
    .brand:hover .brand-mark { color: var(--ink); background: var(--acid); box-shadow: 4px 4px 0 var(--orange); animation: mark-bounce .7s ease-in-out infinite; }
    .brand span { font-size: 18px; transition: letter-spacing .25s ease; }
    .brand:hover span { letter-spacing: .02em; }
    .nav { display: flex; gap: 28px; color: var(--muted); font-size: 13px; }
    .nav a { position: relative; padding-bottom: 5px; }
    .nav a::after { position: absolute; right: 0; bottom: 0; left: 0; height: 2px; background: var(--orange); content: ''; transform: scaleX(0); transform-origin: right; transition: transform .25s ease; }
    .nav a:hover { color: var(--ink); }
    .nav a:hover::after { transform: scaleX(1); transform-origin: left; }
    .live-clock { display: flex; align-items: center; gap: 8px; margin-left: auto; margin-right: 28px; color: var(--muted); font-family: 'Space Grotesk', sans-serif; font-size: 11px; letter-spacing: .08em; white-space: nowrap; }
    .live-clock::before { width: 6px; height: 6px; border-radius: 50%; background: var(--acid); box-shadow: 0 0 0 4px rgba(215, 255, 69, .12); content: ''; animation: blink 1.8s ease-in-out infinite; }
    .live-clock time { color: var(--ink); font-variant-numeric: tabular-nums; }
    .wechat-trigger { position: relative; padding: 0 0 5px; border: 0; background: none; color: var(--muted); font: inherit; cursor: pointer; }
    .wechat-trigger::after { position: absolute; right: 0; bottom: 0; left: 0; height: 2px; background: var(--orange); content: ''; transform: scaleX(0); transform-origin: right; transition: transform .25s ease; }
    .wechat-trigger:hover { color: var(--ink); }
    .wechat-trigger:hover::after { transform: scaleX(1); transform-origin: left; }
    .view-mode-toggle { padding: 7px 10px; border: 1px solid var(--acid); background: transparent; color: var(--acid); font: 700 11px 'Space Grotesk', sans-serif; white-space: nowrap; cursor: pointer; transition: background .2s ease, color .2s ease; }
    .view-mode-toggle:hover { background: var(--acid); color: #07111e; }
    .view-mode-toggle:focus-visible { outline: 2px solid var(--ink); outline-offset: 3px; }

    .hero { display: grid; grid-template-columns: 1.15fr .85fr; gap: 70px; align-items: end; min-height: 510px; padding: 92px 0 74px; }
    .eyebrow { display: flex; align-items: center; gap: 9px; margin-bottom: 22px; color: var(--muted); font-family: 'Space Grotesk', sans-serif; font-size: 12px; letter-spacing: .14em; text-transform: uppercase; }
    .pulse { width: 8px; height: 8px; border-radius: 50%; background: var(--orange); box-shadow: 0 0 0 6px rgba(255, 113, 75, .16); animation: blink 1.8s ease-in-out infinite; }
    h1 { max-width: 720px; margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: clamp(54px, 8vw, 108px); line-height: .88; letter-spacing: -.08em; animation: rise-in .8s .15s ease both; }
    h1 em { color: var(--orange); font-style: normal; animation: accent-breathe 2.8s ease-in-out infinite; }
    .hero-copy { max-width: 390px; margin: 30px 0 0; padding-left: 16px; border-left: 2px solid var(--acid); color: var(--muted); font-size: 16px; line-height: 1.65; animation: rise-in .7s .35s ease both; }
    .hero-side { display: flex; flex-direction: column; gap: 18px; align-self: center; animation: rise-in .8s .25s ease both; }
    .orbit { position: relative; display: grid; width: 190px; height: 190px; margin-left: auto; place-items: center; border: 1px solid var(--ink); border-radius: 50%; background: rgba(215, 255, 69, .84); box-shadow: 24px 24px 0 rgba(3, 10, 20, .78); color: #07111e; font-family: 'Space Grotesk', sans-serif; font-size: 42px; font-weight: 700; letter-spacing: -.1em; transform: rotate(12deg); animation: float-orbit 4s ease-in-out infinite; }
    .orbit::before { position: absolute; inset: -13px; border: 2px dashed var(--orange); border-radius: 50%; content: ''; animation: orbit-spin 8s linear infinite; }
    .hero-note { max-width: 240px; margin-left: auto; color: var(--muted); font-family: 'Space Grotesk', sans-serif; font-size: 12px; line-height: 1.5; text-align: right; animation: rise-in .7s .45s ease both; transition: color .25s ease, transform .25s ease; }
    .hero-note:hover { color: var(--ink); transform: translateX(-5px); }

    .status-rail { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; margin: 0 0 76px; border: 1px solid var(--line); background: var(--line); box-shadow: 0 18px 44px rgba(2, 9, 19, .18); animation: rise-in .7s .45s ease both; }
    .status-item { position: relative; padding: 18px 20px; overflow: hidden; background: rgba(8, 19, 33, .46); }
    .status-item::after { position: absolute; right: 20px; bottom: 18px; width: 34px; height: 2px; background: var(--acid); content: ''; transition: width .3s ease; }
    .status-item:hover::after { width: 70px; }
    .status-label { display: block; margin-bottom: 8px; color: var(--muted); font-family: 'Space Grotesk', sans-serif; font-size: 10px; letter-spacing: .14em; text-transform: uppercase; }
    .status-value { display: block; font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; }

    .section-head { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin: 30px 0 22px; animation: rise-in .65s .2s ease both; }
    .section-kicker { margin: 0 0 7px; color: var(--muted); font-family: 'Space Grotesk', sans-serif; font-size: 12px; letter-spacing: .14em; text-transform: uppercase; }
    h2 { position: relative; display: inline-block; margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: clamp(30px, 4vw, 48px); letter-spacing: -.06em; }
    h2::after { position: absolute; right: 0; bottom: -8px; left: 0; height: 3px; background: var(--acid); content: ''; transform: scaleX(.35); transform-origin: left; transition: transform .35s ease; }
    .section-head:hover h2::after { transform: scaleX(1); }
    .count { color: var(--muted); font-size: 13px; transition: color .25s ease, transform .25s ease; }
    .count.changed { animation: count-pop .45s ease; }
    .section-head:hover .count { color: var(--orange); transform: translateX(-4px); }
    .toolbar { display: flex; gap: 10px; margin-bottom: 28px; animation: rise-in .65s .3s ease both; }
    .search { width: min(320px, 100%); padding: 13px 16px; border: 1px solid var(--line); border-radius: 0; outline: none; background: rgba(255, 255, 255, .06); color: var(--ink); font: inherit; transition: width .35s ease, background .25s ease, box-shadow .25s ease; }
    .search:focus { width: min(380px, 100%); background: rgba(255, 255, 255, .12); }
    .search:focus { border-color: var(--ink); box-shadow: 4px 4px 0 var(--acid); }
    .filter { padding: 0 15px; border: 1px solid var(--ink); background: transparent; color: var(--ink); font: inherit; cursor: pointer; transition: background .2s ease, color .2s ease, transform .2s ease; }
    .filter:active { transform: translateY(2px); }
    .filter { position: relative; overflow: hidden; }
    .filter::before { position: absolute; inset: 0; background: var(--orange); content: ''; transform: translateY(100%); transition: transform .25s ease; z-index: -1; }
    .filter:hover::before, .filter.active::before { transform: translateY(0); }
    .filter.active, .filter:hover { background: var(--ink); color: var(--paper); }
    .resource-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; padding-bottom: 110px; perspective: 1000px; }
    .resource-card { display: flex; min-height: 260px; flex-direction: column; justify-content: space-between; padding: 24px; border: 1px solid rgba(243, 245, 239, .28); border-radius: 6px; background: linear-gradient(145deg, var(--glass-light), rgba(255, 255, 255, .025)); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08), 0 18px 40px rgba(2, 9, 19, .18); transition: transform .25s ease, box-shadow .25s ease, background .25s ease, border-color .25s ease; animation: rise-in .7s ease both; }
    .resource-card:nth-child(2) { animation-delay: .12s; }
    .resource-card:nth-child(3) { animation-delay: .24s; }
    .resource-card:nth-child(4) { animation-delay: .36s; }
    .resource-card.reveal { animation: card-pop .55s cubic-bezier(.2, .75, .3, 1) both; }
    .resource-card.scroll-reveal { opacity: 0; transform: translateY(28px); }
    .resource-card.scroll-reveal.is-visible { animation: card-pop .65s cubic-bezier(.2, .75, .3, 1) both; }
    .resource-card:hover { transform: translate(-5px, -8px) rotate(-.5deg); border-color: var(--acid); box-shadow: 10px 14px 0 rgba(3, 10, 20, .68), 0 22px 45px rgba(2, 9, 19, .28); background: linear-gradient(145deg, rgba(255, 255, 255, .14), rgba(255, 255, 255, .04)); }
    .resource-card.hidden { display: none; }
    .empty-state { grid-column: 1 / -1; padding: 46px 24px; border: 1px dashed var(--line); color: var(--muted); text-align: center; }
    .empty-state strong { display: block; margin-bottom: 8px; color: var(--ink); font-family: 'Space Grotesk', sans-serif; font-size: 20px; }
    .card-top { display: flex; justify-content: space-between; align-items: start; }
    .tag { padding: 5px 8px; background: var(--cyan); font-family: 'Space Grotesk', sans-serif; font-size: 11px; font-weight: 700; letter-spacing: .04em; transition: background .25s ease, transform .25s ease; }
    .resource-card:hover .tag { background: var(--acid); transform: translateY(-3px) rotate(-2deg); }
    .number { color: var(--muted); font-family: 'Space Grotesk', sans-serif; font-size: 12px; transition: color .25s ease; }
    .resource-card:hover .number { color: var(--orange); }
    .resource-card h3 { max-width: 240px; margin: 35px 0 8px; font-family: 'Space Grotesk', sans-serif; font-size: 25px; letter-spacing: -.05em; transition: transform .25s ease, color .25s ease; }
    .resource-card:hover h3 { color: var(--orange); transform: translateX(5px); }
    .resource-card p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; transition: color .25s ease; }
    .resource-card:hover p { color: var(--ink); }
    .card-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 28px; }
    .meta { color: var(--muted); font-size: 11px; transition: color .25s ease; }
    .resource-card:hover .meta { color: var(--ink); }
    .download-count { color: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; transition: color .25s ease; }
    .resource-card:hover .download-count { color: var(--acid); }
    .download { display: inline-flex; align-items: center; gap: 8px; padding: 10px 13px; border: 1px solid transparent; border-radius: 2px; background: var(--ink); color: var(--paper); font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 700; }
    .download { transition: transform .2s ease, background .2s ease, color .2s ease; }
    .download:hover { background: var(--orange); color: var(--ink); transform: translate(-2px, -2px); }
    .download:active { transform: translate(0, 1px); }
    .download:focus-visible, .filter:focus-visible, .search:focus-visible { outline: 2px solid var(--orange); outline-offset: 3px; }
    .download::after { content: '↓'; font-size: 16px; line-height: 10px; }
    .download.downloaded { background: var(--acid); color: #07111e; }
    .auth-modal[hidden] { display: none; }
    .auth-modal { position: fixed; inset: 0; z-index: 35; display: grid; place-items: center; padding: 20px; background: rgba(1, 8, 17, .76); backdrop-filter: blur(10px); animation: fade-in .25s ease both; }
    .auth-panel { position: relative; width: min(100%, 410px); padding: 32px 28px 28px; border: 1px solid rgba(243, 245, 239, .28); background: linear-gradient(145deg, #173d67, #0b1b31); box-shadow: 10px 12px 0 rgba(1, 8, 17, .7); animation: card-pop .35s cubic-bezier(.2, .75, .3, 1) both; }
    .auth-close { position: absolute; top: 12px; right: 14px; width: 30px; height: 30px; border: 1px solid var(--line); background: transparent; color: var(--ink); font-size: 20px; cursor: pointer; }
    .auth-close:hover { background: var(--orange); color: #07111e; }
    .auth-kicker { margin: 0 0 8px; color: var(--acid); font: 11px 'Space Grotesk', sans-serif; letter-spacing: .14em; text-transform: uppercase; }
    .auth-panel h2 { margin: 0 0 18px; font-size: 30px; }
    .auth-panel h2::after { display: none; }
    .auth-tabs { display: flex; gap: 8px; margin-bottom: 18px; }
    .auth-tabs[hidden], .auth-form[hidden], .logout-panel[hidden] { display: none; }
    .auth-tab { padding: 8px 12px; border: 1px solid var(--line); background: transparent; color: var(--muted); cursor: pointer; }
    .auth-tab.active, .auth-tab:hover { border-color: var(--acid); background: var(--acid); color: #07111e; }
    .auth-form { display: grid; gap: 12px; }
    .auth-form input { width: 100%; padding: 12px 14px; border: 1px solid var(--line); background: rgba(255, 255, 255, .08); color: var(--ink); font: inherit; outline: none; }
    .auth-form input:focus { border-color: var(--acid); box-shadow: 4px 4px 0 rgba(215, 255, 69, .35); }
    .auth-submit { padding: 12px; border: 0; background: var(--acid); color: #07111e; font: 700 13px 'Space Grotesk', sans-serif; cursor: pointer; }
    .auth-submit:hover { background: var(--orange); }
    .forgot-password-trigger { justify-self: end; padding: 0; border: 0; background: transparent; color: var(--cyan); font: inherit; font-size: 12px; cursor: pointer; }
    .forgot-password-trigger:hover { color: var(--acid); text-decoration: underline; }
    .reset-review-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .reset-review-actions button { padding: 8px 12px; border: 1px solid var(--acid); background: transparent; color: var(--acid); cursor: pointer; }
    .reset-review-actions button:hover { background: var(--acid); color: #07111e; }
    .reset-review-actions .reset-reject { border-color: var(--orange); color: var(--orange); }
    .reset-review-actions .reset-reject:hover { background: var(--orange); color: #07111e; }
    .auth-message { min-height: 20px; margin: 12px 0 0; color: var(--muted); font-size: 12px; }
    .auth-message.error { color: var(--orange); }
    .logout-panel { padding: 18px; border: 1px solid var(--line); background: rgba(255, 255, 255, .06); color: var(--muted); text-align: center; }
    .logout-panel strong { display: block; margin-bottom: 16px; color: var(--ink); font-family: 'Space Grotesk', sans-serif; font-size: 18px; }
    .logout-button { padding: 11px 18px; border: 1px solid var(--orange); background: transparent; color: var(--orange); font: 700 13px 'Space Grotesk', sans-serif; cursor: pointer; transition: background .2s ease, color .2s ease; }
    .logout-button:hover { background: var(--orange); color: #07111e; }
    .logout-confirm[hidden] { display: none; }
    .logout-confirm { position: fixed; inset: 0; z-index: 45; display: grid; place-items: center; padding: 20px; background: rgba(1, 8, 17, .68); backdrop-filter: blur(8px); animation: fade-in .2s ease both; }
    .logout-confirm-panel { width: min(100%, 360px); padding: 28px; border: 1px solid rgba(243, 245, 239, .3); background: #102947; box-shadow: 8px 9px 0 rgba(1, 8, 17, .7); text-align: center; animation: card-pop .3s cubic-bezier(.2, .75, .3, 1) both; }
    .logout-confirm-panel h3 { margin: 0 0 10px; font-family: 'Space Grotesk', sans-serif; font-size: 22px; }
    .logout-confirm-panel p { margin: 0 0 22px; color: var(--muted); font-size: 13px; }
    .logout-confirm-actions { display: flex; justify-content: center; gap: 10px; }
    .logout-confirm-actions button { padding: 10px 16px; border: 1px solid var(--line); font: 700 12px 'Space Grotesk', sans-serif; cursor: pointer; transition: transform .2s ease, background .2s ease; }
    .logout-confirm-actions button:hover { transform: translateY(-2px); }
    .logout-cancel { background: transparent; color: var(--ink); }
    .logout-cancel:hover { background: rgba(255, 255, 255, .1); }
    .logout-approve { border-color: var(--orange) !important; background: var(--orange); color: #07111e; }
    .account-badge { margin-left: 10px; color: var(--acid); font-size: 11px; }

    #idy_floatdiv { position: fixed; right: 18px; bottom: 28px; z-index: 20; padding: 13px 18px; border: 2px solid var(--ink); background: var(--acid); box-shadow: 6px 6px 0 var(--orange); color: #07111e; font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 700; line-height: 1; transition: transform .25s ease, background .25s ease, box-shadow .25s ease; }
    #idy_floatdiv:hover { background: var(--orange); box-shadow: 4px 4px 0 var(--ink); transform: translate(-2px, -3px); }
    #idy_floatdiv a { display: block; color: #07111e !important; white-space: nowrap; }
    #idy_floatdiv a::after { content: '  ↗'; }
    body.mobile-mode #idy_floatdiv { right: max(14px, calc((100% - 560px) / 2 + 14px)); bottom: 18px; }

    .wechat-modal[hidden] { display: none; }
    .wechat-modal { position: fixed; inset: 0; z-index: 30; display: grid; place-items: center; padding: 20px; background: rgba(1, 8, 17, .72); backdrop-filter: blur(10px); animation: fade-in .25s ease both; }
    .wechat-panel { position: relative; width: min(100%, 390px); padding: 34px 28px 28px; border: 1px solid rgba(243, 245, 239, .28); border-radius: 8px; background: linear-gradient(145deg, #173d67, #0b1b31); box-shadow: 12px 14px 0 rgba(1, 8, 17, .72); text-align: center; animation: card-pop .35s cubic-bezier(.2, .75, .3, 1) both; }
    .wechat-close { position: absolute; top: 12px; right: 14px; width: 32px; height: 32px; border: 1px solid var(--line); background: transparent; color: var(--ink); font-size: 20px; line-height: 1; cursor: pointer; transition: background .2s ease, color .2s ease; }
    .wechat-close:hover { background: var(--orange); color: #07111e; }
    .wechat-kicker { margin: 0 0 10px; color: var(--acid); font-family: 'Space Grotesk', sans-serif; font-size: 11px; letter-spacing: .14em; text-transform: uppercase; }
    .wechat-panel h2 { margin: 0 0 10px; font-size: 32px; }
    .wechat-panel h2::after { display: none; }
    .wechat-caption { margin: 0 0 18px; color: var(--muted); font-size: 13px; }
    .wechat-id { display: block; margin: 0 0 18px; padding: 14px; border: 1px solid rgba(215, 255, 69, .35); background: rgba(215, 255, 69, .1); color: var(--acid); font-family: 'Space Grotesk', monospace; font-size: 16px; user-select: all; }
    .copy-wechat { padding: 11px 18px; border: 0; background: var(--acid); color: #07111e; font: 700 13px 'Space Grotesk', sans-serif; cursor: pointer; transition: transform .2s ease, background .2s ease; }
    .copy-wechat:hover { background: var(--orange); transform: translateY(-2px); }
    .copy-wechat.copied { background: var(--cyan); }
    .copy-toast[hidden] { display: none; }
    .copy-toast { position: fixed; top: 50%; left: 50%; z-index: 40; display: flex; align-items: center; gap: 10px; padding: 16px 22px; border: 1px solid rgba(215, 255, 69, .55); background: rgba(8, 19, 33, .92); box-shadow: 7px 7px 0 rgba(1, 8, 17, .7); color: var(--acid); font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; transform: translate(-50%, -50%); animation: toast-pop .3s cubic-bezier(.2, .75, .3, 1) both; }
    .copy-toast::before { content: '✓'; color: var(--cyan); font-size: 20px; }

    @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
    @keyframes toast-pop { from { opacity: 0; transform: translate(-50%, -50%) scale(.82); } to { opacity: 1; transform: translate(-50%, -50%) scale(1); } }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; }
      .resource-card.scroll-reveal { opacity: 1; transform: none; }
      .background-system::before, .background-system::after { animation: none; }
    }

    footer { border-top: 1px solid var(--line); padding: 24px 0 34px; color: var(--muted); font-size: 12px; animation: footer-rise .8s .55s ease both; }
    .footer-line { display: flex; justify-content: space-between; gap: 20px; }
    .footer-line strong { color: var(--ink); font-family: 'Space Grotesk', sans-serif; }
    .admin-trigger { padding: 0; border: 0; background: transparent; color: var(--muted); font: inherit; cursor: pointer; transition: color .2s ease; }
    .admin-trigger:hover { color: var(--acid); }
    .admin-link { padding: 0; border: 0; background: transparent; color: var(--muted); font: inherit; cursor: pointer; transition: color .2s ease; }
    .admin-link:hover { color: var(--acid); }
    .announcement-box { margin: 0 0 18px; padding: 18px 20px; border: 1px solid rgba(141, 232, 237, .38); background: linear-gradient(100deg, rgba(141, 232, 237, .16), rgba(141, 232, 237, .05)); box-shadow: 5px 5px 0 rgba(1, 8, 17, .35); }
    .announcement-box strong { display: block; margin-bottom: 6px; color: var(--cyan); font-family: 'Space Grotesk', sans-serif; }
    .announcement-box p { margin: 0; color: var(--ink); line-height: 1.6; }
    .password-reset-notice { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 24px; padding: 18px 20px; border: 1px solid rgba(215, 255, 69, .48); background: linear-gradient(100deg, rgba(215, 255, 69, .14), rgba(215, 255, 69, .04)); box-shadow: 5px 5px 0 rgba(1, 8, 17, .35); }
    .password-reset-notice[hidden] { display: none; }
    .password-reset-notice strong { display: block; margin-bottom: 6px; color: var(--acid); }
    .password-reset-notice p { margin: 0; color: var(--ink); font-size: 13px; line-height: 1.6; }
    .password-reset-notice button { flex-shrink: 0; padding: 11px 15px; border: 0; background: var(--acid); color: #07111e; font: 700 12px 'Space Grotesk', sans-serif; cursor: pointer; }
    .password-reset-notice button:hover { background: var(--orange); }
    .password-reset-rejected { border-color: rgba(255, 113, 75, .55); background: linear-gradient(100deg, rgba(255, 113, 75, .15), rgba(255, 113, 75, .04)); }
    .password-reset-rejected strong { color: var(--orange); }
    @media (max-width: 600px) { .password-reset-notice { align-items: flex-start; flex-direction: column; } }
    .announcement-date { display: block; margin-top: 10px; color: var(--muted); font-size: 11px; }
    .announcement-admin-item { display: flex; align-items: start; justify-content: space-between; gap: 18px; padding: 15px 0; border-bottom: 1px solid var(--line); }
    .announcement-admin-item p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
    .maintenance-screen[hidden] { display: none; }
    .maintenance-screen { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 24px; background: linear-gradient(135deg, #07111f, #12345a); color: var(--ink); }
    .maintenance-grid { position: absolute; inset: 0; opacity: .16; background-image: linear-gradient(rgba(243, 245, 239, .3) 1px, transparent 1px), linear-gradient(90deg, rgba(243, 245, 239, .3) 1px, transparent 1px); background-size: 42px 42px; animation: grid-drift 18s linear infinite; }
    .maintenance-panel { position: relative; width: min(100%, 520px); padding: 44px 30px 30px; border: 1px solid rgba(243, 245, 239, .3); background: rgba(8, 19, 33, .8); box-shadow: 12px 12px 0 rgba(1, 8, 17, .55); text-align: center; backdrop-filter: blur(16px); animation: card-pop .45s cubic-bezier(.2, .75, .3, 1) both; }
    .maintenance-status { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 18px; color: var(--orange); font: 11px 'Space Grotesk', sans-serif; letter-spacing: .14em; text-transform: uppercase; }
    .maintenance-status::before { width: 7px; height: 7px; border-radius: 50%; background: var(--orange); content: ''; animation: blink 1.2s ease-in-out infinite; }
    .maintenance-panel h2 { display: block; margin-bottom: 12px; font-size: clamp(30px, 6vw, 48px); }
    .maintenance-panel h2::after { display: none; }
    .maintenance-panel p { margin: 0 0 24px; color: var(--muted); line-height: 1.7; }
    .unlock-button { display: block; width: fit-content; margin: 0 auto; padding: 7px 12px; border: 0; background: var(--acid); color: #07111e; font: 700 10px 'Space Grotesk', sans-serif; cursor: pointer; transition: transform .2s ease, background .2s ease; }
    .unlock-button:hover { background: var(--orange); transform: translateY(-3px); }
    .maintenance-admin-button { display: block; width: fit-content; margin: 10px auto 0; padding: 6px 11px; border: 1px solid var(--line); color: var(--muted); font: 700 10px 'Space Grotesk', sans-serif; transition: background .2s ease, color .2s ease, transform .2s ease; }
    .maintenance-admin-button:hover { background: var(--cyan); color: #07111e; transform: translateY(-3px); }
    .admin-login-modal[hidden] { display: none; }
    .admin-login-modal { position: fixed; inset: 0; z-index: 120; display: grid; place-items: center; padding: 20px; background: linear-gradient(135deg, rgba(7, 17, 31, .72), rgba(18, 52, 90, .72)), linear-gradient(rgba(243, 245, 239, .12) 1px, transparent 1px), linear-gradient(90deg, rgba(243, 245, 239, .12) 1px, transparent 1px); background-size: auto, 42px 42px, 42px 42px; backdrop-filter: blur(7px); animation: fade-in .25s ease both; }
    .admin-login-panel { position: relative; width: min(100%, 410px); padding: 32px 28px 28px; border: 1px solid rgba(243, 245, 239, .28); background: linear-gradient(145deg, #173d67, #0b1b31); box-shadow: 10px 12px 0 rgba(1, 8, 17, .7); animation: card-pop .35s cubic-bezier(.2, .75, .3, 1) both; }
    .admin-login-close { position: absolute; top: 12px; right: 14px; width: 30px; height: 30px; border: 1px solid var(--line); background: transparent; color: var(--ink); font-size: 20px; cursor: pointer; }
    .admin-login-close:hover { background: var(--orange); color: #07111e; }
    .admin-login-panel h2 { margin: 0 0 18px; font-size: 30px; }
    .admin-login-panel h2::after { display: none; }
    .admin-login-panel form { display: grid; gap: 12px; }
    .admin-login-panel input { width: 100%; padding: 13px 14px; border: 1px solid var(--line); background: rgba(255, 255, 255, .08); color: var(--ink); font: inherit; outline: none; }
    .admin-login-panel input:focus { border-color: var(--acid); box-shadow: 4px 4px 0 rgba(215, 255, 69, .35); }
    .admin-login-submit { padding: 12px; border: 0; background: var(--acid); color: #07111e; font: 700 13px 'Space Grotesk', sans-serif; cursor: pointer; }
    .admin-login-submit:hover { background: var(--orange); }

    @media (max-width: 760px) {
      .shell { width: min(100% - 28px, 560px); }
      .topbar { height: 70px; }
      .background-label { display: none; }
      .background-corner { opacity: .55; }
      .live-clock { margin-right: 14px; font-size: 10px; letter-spacing: 0; }
      .nav { gap: 13px; font-size: 11px; }
      .nav a:nth-child(2) { display: none; }
      .hero { display: block; min-height: auto; padding: 74px 0 64px; }
      h1 { font-size: clamp(55px, 18vw, 100px); }
      .hero-side { margin-top: 54px; }
      .orbit { width: 140px; height: 140px; margin-right: 26px; font-size: 31px; }
      .status-rail { grid-template-columns: 1fr; margin-bottom: 58px; }
      .section-head { display: block; }
      .count { display: block; margin-top: 10px; }
      .toolbar { flex-wrap: wrap; }
      .search { width: 100%; }
      .search:focus { width: 100%; }
      .filter { height: 38px; }
      .resource-grid { grid-template-columns: 1fr; padding-bottom: 70px; }
      .resource-card { min-height: 230px; }
      .footer-line { flex-direction: column; }
    }

    body.mobile-mode {
      background: radial-gradient(ellipse at 50% 0, rgba(42, 105, 158, .42), transparent 34rem), #0b192a;
      animation: none;
    }
    body.mobile-mode::before { right: auto; left: max(0px, calc((100% - 560px) / 2)); width: min(100%, 560px); background-color: rgba(11, 25, 42, .24); }
    body.mobile-mode::after { right: auto; left: max(0px, calc((100% - 560px) / 2)); width: min(100%, 560px); opacity: .5; }
    body.mobile-mode .background-system { right: auto; left: max(0px, calc((100% - 560px) / 2)); width: min(100%, 560px); }
    body.mobile-mode .shell { width: min(100% - 32px, 560px); }
    body.mobile-mode .topbar {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
      height: auto;
      min-height: 0;
      padding: 12px 0 10px;
    }
    body.mobile-mode .brand { gap: 10px; }
    body.mobile-mode .brand-mark { width: 32px; height: 32px; }
    body.mobile-mode .brand span { font-size: 16px; }
    body.mobile-mode .live-clock { display: none; }
    body.mobile-mode .nav {
      display: grid;
      grid-column: 1;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      padding: 9px;
      border: 1px solid var(--line);
      background: rgba(8, 19, 33, .54);
    }
    body.mobile-mode .nav a:nth-child(2) { display: none; }
    body.mobile-mode .nav a,
    body.mobile-mode .nav .wechat-trigger,
    body.mobile-mode .nav .view-mode-toggle {
      display: flex;
      min-width: 0;
      min-height: 42px;
      align-items: center;
      justify-content: center;
      padding: 8px 10px;
      border: 1px solid rgba(243, 245, 239, .16);
      background: rgba(255, 255, 255, .035);
      color: var(--ink);
      font-size: 12px;
      text-align: center;
    }
    body.mobile-mode .nav a::after,
    body.mobile-mode .nav .wechat-trigger::after { display: none; }
    body.mobile-mode .nav .view-mode-toggle {
      grid-column: 1 / -1;
      border-color: rgba(215, 255, 69, .62);
      background: rgba(215, 255, 69, .1);
      color: var(--acid);
    }
    body.mobile-mode .hero { display: block; min-height: 0; padding: 48px 0 42px; }
    body.mobile-mode .eyebrow { margin-bottom: 18px; font-size: 10px; letter-spacing: .1em; }
    body.mobile-mode h1 { font-size: clamp(54px, 16vw, 82px); line-height: .96; letter-spacing: -.075em; }
    body.mobile-mode .hero-copy { max-width: 34ch; margin-top: 20px; padding-left: 13px; font-size: 14px; }
    body.mobile-mode .hero-side {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: center;
      gap: 22px;
      margin-top: 38px;
    }
    body.mobile-mode .orbit {
      width: clamp(104px, 31vw, 132px);
      height: clamp(104px, 31vw, 132px);
      margin: 0 0 0 12px;
      box-shadow: 12px 12px 0 rgba(3, 10, 20, .58);
      font-size: clamp(25px, 7vw, 30px);
    }
    body.mobile-mode .orbit::before { inset: -9px; }
    body.mobile-mode .hero-note {
      max-width: none;
      margin: 0;
      padding-left: 14px;
      border-left: 1px solid rgba(215, 255, 69, .65);
      font-size: 11px;
      line-height: 1.8;
      text-align: left;
    }
    body.mobile-mode .status-rail { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 48px; }
    body.mobile-mode .status-item { padding: 13px 9px; }
    body.mobile-mode .status-item::after { display: none; }
    body.mobile-mode .status-label { min-height: 2.4em; margin-bottom: 6px; font-size: 9px; letter-spacing: .04em; }
    body.mobile-mode .status-value { font-size: clamp(17px, 5vw, 21px); }
    body.mobile-mode .section-head { display: flex; align-items: end; gap: 12px; margin: 24px 0 18px; }
    body.mobile-mode .section-kicker { font-size: 10px; }
    body.mobile-mode h2 { font-size: 34px; }
    body.mobile-mode .count { flex: 0 0 auto; margin: 0 0 3px; font-size: 11px; }
    body.mobile-mode .toolbar {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
      gap: 8px;
      margin-bottom: 20px;
    }
    body.mobile-mode .search,
    body.mobile-mode .search:focus { grid-column: 1 / -1; width: 100%; min-height: 46px; padding: 11px 13px; }
    body.mobile-mode .filter { min-height: 42px; padding: 8px 10px; font-size: 12px; }
    body.mobile-mode .resource-grid { grid-template-columns: 1fr; gap: 12px; padding-bottom: 54px; }
    body.mobile-mode .resource-card {
      min-height: 0;
      gap: 24px;
      padding: 19px;
      border-color: rgba(243, 245, 239, .2);
      background: linear-gradient(145deg, rgba(255, 255, 255, .085), rgba(255, 255, 255, .025));
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, .06), 0 10px 26px rgba(2, 9, 19, .16);
    }
    body.mobile-mode .resource-card:hover { transform: translateY(-2px); }
    body.mobile-mode .resource-card h3 { margin: 22px 0 7px; font-size: 23px; }
    body.mobile-mode .resource-card p { font-size: 13px; }
    body.mobile-mode .card-bottom { display: grid; grid-template-columns: 1fr auto; gap: 8px 12px; margin-top: 0; }
    body.mobile-mode .download { grid-column: 1 / -1; min-height: 42px; justify-content: center; }
    body.mobile-mode .announcement-box { margin-bottom: 14px; padding: 15px; }
    body.mobile-mode .footer-line { align-items: flex-start; flex-direction: column; gap: 10px; }
    body.mobile-mode #idy_floatdiv { right: max(12px, calc((100% - 560px) / 2 + 12px)); bottom: 14px; }
    body.mobile-mode .auth-modal { align-items: end; padding: 12px; }
    body.mobile-mode .auth-panel { width: 100%; max-height: calc(100dvh - 24px); overflow-y: auto; padding: 28px 20px 20px; }

    @media (max-width: 360px) {
      body.mobile-mode .shell { width: calc(100% - 24px); }
      body.mobile-mode .nav { gap: 6px; padding: 7px; }
      body.mobile-mode .hero-side { gap: 16px; }
      body.mobile-mode .status-item { padding-right: 6px; padding-left: 6px; }
      body.mobile-mode .status-label { font-size: 8px; }
      body.mobile-mode .resource-card { padding: 16px; }
    }
  </style>
</head>
<body>
  <div class="background-system" aria-hidden="true">
    <span class="background-label top">signal / 01</span>
    <span class="background-label bottom">archive online / 2026</span>
    <i class="background-corner left"></i>
    <i class="background-corner right"></i>
  </div>
  <header class="shell topbar">
    <a class="brand" href="#top" aria-label="Aiden 首页"><b class="brand-mark">A</b><span>AIDEN / 01</span></a>
    <div class="live-clock" aria-label="当前时间">当前时间 / <time id="liveClock">--:--:--</time></div>
    <nav class="nav" aria-label="主导航">
      <a href="#resources">资源库</a>
      <a href="#about">关于本站</a>
      <button class="wechat-trigger" id="wechatTrigger" type="button">联系我 ↗</button>
      <button class="wechat-trigger" id="accountTrigger" type="button">登录 / 注册</button>
      <a class="view-mode-toggle" href="app.php" aria-label="刷新主页">刷新</a>
      <button class="view-mode-toggle" id="viewModeToggle" type="button" aria-pressed="false">改成手机版模式</button>
    </nav>
  </header>

  <main id="top" class="shell">
    <?php if ($approvedPasswordReset): ?>
    <aside class="password-reset-notice" id="passwordResetNotice" aria-labelledby="passwordResetNoticeTitle">
      <div>
        <strong id="passwordResetNoticeTitle">密码重置申请已通过</strong>
        <p>这是仅此申请浏览器可见的通知。请在原设备、原 IP 下设置新密码。</p>
      </div>
      <button id="openApprovedPasswordReset" type="button">立即重置密码</button>
    </aside>
    <?php elseif ($rejectedPasswordReset): ?>
    <aside class="password-reset-notice password-reset-rejected" id="passwordResetNotice" data-request-id="<?= htmlspecialchars((string) ($passwordResetRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" role="status">
      <div>
        <strong>密码重置申请未通过</strong>
        <p>管理员已拒绝本次申请。拒绝原因：<?= nl2br(htmlspecialchars((string) ($passwordResetRequest['rejection_reason'] ?? '未填写'), ENT_QUOTES, 'UTF-8')) ?></p>
      </div>
    </aside>
    <?php endif; ?>
    <section class="hero" id="about">
      <div>
        <div class="eyebrow"><i class="pulse"></i> 官方资源中心 / 2026</div>
        <h1>创作。<br><em>分享。</em><br>循环。</h1>
        <p class="hero-copy">这里是 Aiden 的官方资源基地。把正在做的东西、值得留下的文件和一些小实验，集中放在一个地方。</p>
      </div>
      <div class="hero-side">
        <div class="orbit">资源<br>已上线</div>
        <p class="hero-note">开放访问<br>随时下载，持续更新<br>最后更新 / 2026.09.13</p>
      </div>
    </section>

    <section class="status-rail" aria-label="站点状态">
      <div class="status-item"><span class="status-label">资源档案</span><strong class="status-value" id="totalCount">04</strong></div>
      <div class="status-item"><span class="status-label">全天开放</span><strong class="status-value">24/7</strong></div>
      <div class="status-item"><span class="status-label">最新更新</span><strong class="status-value">09.13</strong></div>
    </section>

    <section id="resources">
      <?php if ($announcements !== []): $latestAnnouncement = $announcements[0]; ?>
        <div class="announcement-box"><strong>站点公告 / <?= htmlspecialchars($latestAnnouncement['title'], ENT_QUOTES, 'UTF-8') ?></strong><p><?= nl2br(htmlspecialchars($latestAnnouncement['content'], ENT_QUOTES, 'UTF-8')) ?></p><small class="announcement-date">发布于 <?= htmlspecialchars($latestAnnouncement['date'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <?php endif; ?>
      <div class="section-head">
        <div><p class="section-kicker">精选发布</p><h2>资源库</h2></div>
        <span class="count" id="resultCount" aria-live="polite">共 4 个资源</span>
      </div>
      <div class="toolbar" aria-label="资源筛选">
        <input class="search" id="searchInput" type="search" placeholder="搜索资源名称..." aria-label="搜索资源名称">
        <button class="filter active" data-filter="all" type="button">全部</button>
        <?php foreach ($categories as $category): ?><button class="filter" data-filter="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" type="button"><?= htmlspecialchars($category === 'ai' ? '实验' : $category, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?>
      </div>
      <div class="resource-grid" id="resourceGrid">
        <?php foreach ($resources as $index => $resource): ?>
        <article class="resource-card" data-type="<?= htmlspecialchars($resource['type'], ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>">
          <div><div class="card-top"><span class="tag"><?= htmlspecialchars($resource['tag'], ENT_QUOTES, 'UTF-8') ?></span><span class="number"><?= str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) ?></span></div><h3><?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($resource['description'], ENT_QUOTES, 'UTF-8') ?></p></div>
          <div class="card-bottom"><span class="meta">更新于 <?= htmlspecialchars($resource['updated'], ENT_QUOTES, 'UTF-8') ?></span><span class="download-count" data-download-count="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>">下载 0</span><a class="download" href="?download=<?= rawurlencode($resource['file']) ?>" data-download-key="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>">下载资源</a></div>
        </article>
        <?php endforeach; ?>
        <div class="empty-state" id="emptyState" hidden><strong>没有找到匹配资源</strong><span>换个关键词，或切换其他分类继续浏览。</span></div>
      </div>
    </section>
  </main>
  <footer>
    <div class="shell footer-line"><span><strong>AIDEN / 官方站点</strong> · 独立制作，持续更新</span><span>© 2026 Aiden. 用心构建。</span><a class="admin-link" href="app.php?admin=1">后台管理</a><button class="admin-trigger" id="adminTrigger" type="button">网站开发</button></div>
  </footer>

  <div class="maintenance-screen" id="maintenanceScreen" <?= $serverMaintenanceMode ? '' : 'hidden' ?>>
    <div class="maintenance-grid" aria-hidden="true"></div>
    <section class="maintenance-panel" role="dialog" aria-modal="true" aria-labelledby="maintenanceTitle">
      <div class="maintenance-status">维护模式</div>
      <h2 id="maintenanceTitle">网站更新中，无法使用</h2>
      <p>管理员正在进行网站更新，请稍后再回来。</p>
      <button class="unlock-button" id="unlockButton" type="button">解锁网站</button><a class="maintenance-admin-button" href="app.php?admin=1">进入后台</a>
    </section>
  </div>

  <div class="admin-login-modal" id="maintenanceLockModal" role="dialog" aria-modal="true" aria-labelledby="maintenanceLockTitle" hidden>
    <div class="admin-login-panel">
      <button class="admin-login-close" id="maintenanceLockClose" type="button" aria-label="关闭">×</button>
      <p class="auth-kicker">网站维护</p>
      <h2 id="maintenanceLockTitle">进入维护模式</h2>
      <form method="post" action="app.php">
        <input type="hidden" name="maintenance_action" value="lock">
        <input name="password" type="password" placeholder="请输入管理员密码" autocomplete="current-password" required>
        <button class="admin-login-submit" type="submit">确认进入</button>
      </form>
    </div>
  </div>

  <div class="admin-login-modal" id="unlockLoginModal" role="dialog" aria-modal="true" aria-labelledby="unlockLoginTitle" hidden>
    <div class="admin-login-panel">
      <button class="admin-login-close" id="unlockLoginClose" type="button" aria-label="关闭">×</button>
      <p class="auth-kicker">维护模式</p>
      <h2 id="unlockLoginTitle">解锁网页</h2>
      <form id="unlockLoginForm">
        <input id="unlockPassword" type="password" placeholder="请输入解锁密码" autocomplete="current-password" required>
        <button class="admin-login-submit" type="submit">确认解锁</button>
      </form>
      <p class="auth-message" id="unlockMessage" aria-live="polite"></p>
    </div>
  </div>

  <div class="wechat-modal" id="wechatModal" role="dialog" aria-modal="true" aria-labelledby="wechatTitle" hidden>
    <div class="wechat-panel">
      <button class="wechat-close" id="wechatClose" type="button" aria-label="关闭">×</button>
      <p class="wechat-kicker">微信联系方式</p>
      <h2 id="wechatTitle">联系我</h2>
      <p class="wechat-caption">添加微信时请备注你的来意。</p>
      <code class="wechat-id" id="wechatId" tabindex="0">wxid_ycg7s261081g22</code>
      <button class="copy-wechat" id="copyWechat" type="button">复制微信号</button>
    </div>
  </div>
  <div class="copy-toast" id="copyToast" role="status" aria-live="polite" hidden>微信号复制成功</div>

  <div class="logout-confirm" id="logoutConfirm" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" hidden>
    <div class="logout-confirm-panel">
      <h3 id="logoutConfirmTitle">确定退出登录？</h3>
      <p>退出后，下次下载资源需要重新登录。</p>
      <div class="logout-confirm-actions">
        <button class="logout-cancel" id="logoutCancel" type="button">暂不退出</button>
        <button class="logout-approve" id="logoutApprove" type="button">确认退出</button>
      </div>
    </div>
  </div>

  <div class="auth-modal" id="authModal" role="dialog" aria-modal="true" aria-labelledby="authTitle" hidden>
    <div class="auth-panel">
      <button class="auth-close" id="authClose" type="button" aria-label="关闭">×</button>
      <p class="auth-kicker">资源访问</p>
      <h2 id="authTitle">登录账户</h2>
      <div class="auth-tabs">
        <button class="auth-tab active" data-auth-mode="login" type="button">登录</button>
        <button class="auth-tab" data-auth-mode="register" type="button">注册</button>
      </div>
      <div class="logout-panel" id="logoutPanel" hidden>
        <strong id="loggedInName"></strong>
        <button class="logout-button" id="logoutButton" type="button">退出登录</button>
      </div>
      <form class="auth-form" id="authForm">
        <input id="authUsername" name="username" type="text" minlength="2" maxlength="30" placeholder="用户名" autocomplete="username" required>
        <input id="authPassword" name="password" type="password" minlength="6" placeholder="密码（至少 6 位）" autocomplete="current-password" required>
        <button class="auth-submit" type="submit">登录</button>
      </form>
      <button class="forgot-password-trigger" id="forgotPasswordTrigger" type="button">忘记密码？</button>
      <form class="auth-form" id="passwordResetRequestForm" hidden>
        <input id="passwordResetUsername" name="username" type="text" minlength="2" maxlength="30" placeholder="请输入用户名" autocomplete="username" required>
        <button class="auth-submit" type="submit">提交重置申请</button>
        <button class="forgot-password-trigger" id="cancelPasswordResetRequest" type="button">返回登录</button>
      </form>
      <form class="auth-form" id="passwordResetForm" hidden>
        <input name="new_password" type="password" minlength="6" placeholder="新密码（至少 6 位）" autocomplete="new-password" required>
        <button class="auth-submit" type="submit">设置新密码</button>
        <button class="forgot-password-trigger" id="cancelPasswordReset" type="button">返回登录</button>
      </form>
      <p class="auth-message" id="passwordResetStatus" aria-live="polite" hidden></p>
      <p class="auth-message" id="authMessage" aria-live="polite"></p>
    </div>
  </div>

  <script>
    const viewModeToggle = document.querySelector('#viewModeToggle');
    const viewModeStorageKey = 'aiden-view-mode';
    const storedViewMode = localStorage.getItem(viewModeStorageKey);
    const initialMobileMode = storedViewMode ? storedViewMode === 'mobile' : window.matchMedia('(max-width: 760px)').matches;
    document.body.classList.toggle('mobile-mode', initialMobileMode);
    viewModeToggle.setAttribute('aria-pressed', String(initialMobileMode));
    viewModeToggle.textContent = initialMobileMode ? '改为电脑版模式' : '改成手机版模式';
    viewModeToggle.addEventListener('click', () => {
      const mobileMode = document.body.classList.toggle('mobile-mode');
      localStorage.setItem(viewModeStorageKey, mobileMode ? 'mobile' : 'desktop');
      viewModeToggle.setAttribute('aria-pressed', String(mobileMode));
      viewModeToggle.textContent = mobileMode ? '改为电脑版模式' : '改成手机版模式';
    });

    const cards = [...document.querySelectorAll('.resource-card')];
    const buttons = [...document.querySelectorAll('.filter')];
    const searchInput = document.querySelector('#searchInput');
    const resultCount = document.querySelector('#resultCount');
    const totalCount = document.querySelector('#totalCount');
    const emptyState = document.querySelector('#emptyState');
    const wechatTrigger = document.querySelector('#wechatTrigger');
    const wechatModal = document.querySelector('#wechatModal');
    const wechatClose = document.querySelector('#wechatClose');
    const wechatId = document.querySelector('#wechatId');
    const copyWechat = document.querySelector('#copyWechat');
    const copyToast = document.querySelector('#copyToast');
    const liveClock = document.querySelector('#liveClock');
    const accountTrigger = document.querySelector('#accountTrigger');
    const authModal = document.querySelector('#authModal');
    const authClose = document.querySelector('#authClose');
    const authForm = document.querySelector('#authForm');
    const forgotPasswordTrigger = document.querySelector('#forgotPasswordTrigger');
    const passwordResetRequestForm = document.querySelector('#passwordResetRequestForm');
    const passwordResetUsername = document.querySelector('#passwordResetUsername');
    const passwordResetForm = document.querySelector('#passwordResetForm');
    const passwordResetStatus = document.querySelector('#passwordResetStatus');
    const authTitle = document.querySelector('#authTitle');
    const authMessage = document.querySelector('#authMessage');
    const authTabs = [...document.querySelectorAll('.auth-tab')];
    const logoutPanel = document.querySelector('#logoutPanel');
    const loggedInName = document.querySelector('#loggedInName');
    const logoutButton = document.querySelector('#logoutButton');
    const logoutConfirm = document.querySelector('#logoutConfirm');
    const logoutCancel = document.querySelector('#logoutCancel');
    const logoutApprove = document.querySelector('#logoutApprove');
    let passwordResetPoll = null;
    let rejectionNoticeTimer = null;
    const serverUser = <?= json_encode($_SESSION['user'] ?? null, JSON_UNESCAPED_UNICODE) ?>;
    let approvedPasswordReset = <?= $approvedPasswordReset ? 'true' : 'false' ?>;
    const rejectedPasswordResetRequestId = <?= json_encode($rejectedPasswordReset ? (string) ($passwordResetRequest['id'] ?? '') : '') ?>;
    const rejectedPasswordResetReason = <?= json_encode($rejectedPasswordReset ? (string) ($passwordResetRequest['rejection_reason'] ?? '未填写') : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let authMode = 'login';
    const adminTrigger = document.querySelector('#adminTrigger');
    const maintenanceScreen = document.querySelector('#maintenanceScreen');
    const unlockButton = document.querySelector('#unlockButton');
    const maintenanceLockModal = document.querySelector('#maintenanceLockModal');
    const maintenanceLockClose = document.querySelector('#maintenanceLockClose');
    const unlockLoginModal = document.querySelector('#unlockLoginModal');
    const unlockLoginClose = document.querySelector('#unlockLoginClose');
    const unlockLoginForm = document.querySelector('#unlockLoginForm');
    const unlockPassword = document.querySelector('#unlockPassword');
    const unlockMessage = document.querySelector('#unlockMessage');
    const serverMaintenanceMode = <?= $serverMaintenanceMode ? 'true' : 'false' ?>;
    const downloadCounts = [...document.querySelectorAll('.download-count')];
    const downloadStorageKey = 'aiden-download-counts';
    const selectAllResources = document.querySelector('#selectAllResources');
    const resourceCheckboxes = [...document.querySelectorAll('.resource-checkbox')];
    const selectAllCategories = document.querySelector('#selectAllCategories');
    const categoryCheckboxes = [...document.querySelectorAll('.category-checkbox')];
    let activeFilter = 'all';
    let pendingDownload = null;
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function showRejectedPasswordResetNotice(requestId, reason) {
      const requestKey = String(requestId || '');
      if (!requestKey) return;
      let notice = document.querySelector('#passwordResetNotice');
      if (!notice) {
        notice = document.createElement('aside');
        notice.id = 'passwordResetNotice';
        const main = document.querySelector('#top');
        if (!main) return;
        main.insertBefore(notice, main.firstElementChild);
      }
      notice.classList.add('password-reset-notice', 'password-reset-rejected');
      notice.dataset.requestId = requestKey;
      notice.setAttribute('role', 'status');
      notice.replaceChildren();
      const content = document.createElement('div');
      const title = document.createElement('strong');
      const message = document.createElement('p');
      title.textContent = '密码重置申请未通过';
      message.textContent = `管理员已拒绝本次申请。拒绝原因：${reason || '未填写'}`;
      content.append(title, message);
      notice.append(content);

      const storageKey = `aiden-password-reset-rejection-${requestKey}`;
      let expiresAt;
      try {
        expiresAt = Number(window.localStorage.getItem(storageKey));
        if (!Number.isFinite(expiresAt) || expiresAt <= 0) {
          expiresAt = Date.now() + 30000;
          window.localStorage.setItem(storageKey, String(expiresAt));
        }
      } catch {
        expiresAt = Date.now() + 30000;
      }
      const remaining = Math.max(0, expiresAt - Date.now());
      notice.hidden = remaining === 0;
      if (rejectionNoticeTimer !== null) window.clearTimeout(rejectionNoticeTimer);
      rejectionNoticeTimer = window.setTimeout(() => {
        if (notice.dataset.requestId === requestKey) notice.hidden = true;
        if (passwordResetStatus.dataset.rejectionRequestId === requestKey) {
          passwordResetStatus.hidden = true;
          authModal.hidden = true;
        }
        rejectionNoticeTimer = null;
      }, remaining);
    }

    if (rejectedPasswordResetRequestId) {
      showRejectedPasswordResetNotice(rejectedPasswordResetRequestId, rejectedPasswordResetReason);
    }

    if (selectAllResources) {
      selectAllResources.addEventListener('change', () => {
        resourceCheckboxes.forEach((checkbox) => { checkbox.checked = selectAllResources.checked; });
      });
      resourceCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
        selectAllResources.checked = resourceCheckboxes.length > 0 && resourceCheckboxes.every((item) => item.checked);
      }));
    }
    if (selectAllCategories) {
      selectAllCategories.addEventListener('change', () => {
        categoryCheckboxes.forEach((checkbox) => { checkbox.checked = selectAllCategories.checked; });
      });
      categoryCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
        selectAllCategories.checked = categoryCheckboxes.length > 0 && categoryCheckboxes.every((item) => item.checked);
      }));
    }

    const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: .2 });

    cards.forEach((card) => {
      card.classList.add('scroll-reveal');
      revealObserver.observe(card);

      if (!prefersReducedMotion) {
        card.addEventListener('pointermove', (event) => {
          const bounds = card.getBoundingClientRect();
          const x = (event.clientX - bounds.left) / bounds.width - .5;
          const y = (event.clientY - bounds.top) / bounds.height - .5;
          card.style.transform = `translate(-5px, -5px) rotateX(${y * -3}deg) rotateY(${x * 3}deg) rotate(-.5deg)`;
        });
        card.addEventListener('pointerleave', () => {
          card.style.transform = '';
        });
      }
    });

    function renderResources() {
      if (!searchInput || !resultCount || !emptyState) return;
      const query = searchInput.value.trim().toLowerCase();
      let visible = 0;
      cards.forEach((card) => {
        const matchesType = activeFilter === 'all' || card.dataset.type === activeFilter;
        const matchesQuery = card.dataset.name.toLowerCase().includes(query);
        const show = matchesType && matchesQuery;
        card.classList.toggle('hidden', !show);
        if (show) {
          card.classList.remove('reveal');
          void card.offsetWidth;
          card.style.animationDelay = `${visible * 70}ms`;
          card.classList.add('reveal');
          visible += 1;
        }
      });
      resultCount.textContent = `共 ${visible} 个资源`;
      emptyState.hidden = visible !== 0;
      resultCount.classList.remove('changed');
      void resultCount.offsetWidth;
      resultCount.classList.add('changed');
    }

    buttons.forEach((button) => button.addEventListener('click', () => {
      activeFilter = button.dataset.filter;
      buttons.forEach((item) => item.classList.toggle('active', item === button));
      renderResources();
    }));
    if (searchInput) searchInput.addEventListener('input', renderResources);
    const savedDownloadCounts = JSON.parse(localStorage.getItem(downloadStorageKey) || '{}');
    downloadCounts.forEach((count) => {
      const key = count.dataset.downloadCount;
      count.textContent = `下载 ${savedDownloadCounts[key] || 0}`;
    });
    document.querySelectorAll('.download').forEach((download) => download.addEventListener('click', (event) => {
      if (!serverUser) {
        event.preventDefault();
        pendingDownload = download.href;
        openAuth('login');
        return;
      }
      const key = download.dataset.downloadKey;
      savedDownloadCounts[key] = (savedDownloadCounts[key] || 0) + 1;
      localStorage.setItem(downloadStorageKey, JSON.stringify(savedDownloadCounts));
      const count = document.querySelector(`[data-download-count="${key}"]`);
      count.textContent = `下载 ${savedDownloadCounts[key]}`;
      download.classList.add('downloaded');
      window.setTimeout(() => download.classList.remove('downloaded'), 900);
    }));
    document.addEventListener('keydown', (event) => {
      if (event.key === '/' && document.activeElement !== searchInput) {
        event.preventDefault();
        searchInput.focus();
      }
      if (event.key === 'Escape' && document.activeElement === searchInput) {
        searchInput.value = '';
        renderResources();
        searchInput.blur();
      }
      if (event.key === 'Escape' && !wechatModal.hidden) closeWechat();
      if (event.key === 'Escape' && !authModal.hidden) closeAuth();
      if (event.key === 'Escape' && !logoutConfirm.hidden) {
        logoutConfirm.hidden = true;
        logoutButton.focus();
      }
      if (event.key === 'Escape' && !maintenanceLockModal.hidden) {
        maintenanceLockModal.hidden = true;
        adminTrigger.focus();
      }
      if (event.key === 'Escape' && !unlockLoginModal.hidden) {
        unlockLoginModal.hidden = true;
        unlockButton.focus();
      }
    });
    if (totalCount) totalCount.textContent = String(cards.length).padStart(2, '0');
    renderResources();

    function updateClock() {
      liveClock.textContent = new Intl.DateTimeFormat('zh-CN', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      }).format(new Date());
    }

    updateClock();
    window.setInterval(updateClock, 1000);

    async function checkMaintenanceStatus() {
      try {
        const response = await fetch(`${window.location.pathname}?maintenance-check=1&t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) return;
        const status = await response.json();
        if (status.maintenance !== serverMaintenanceMode) window.location.reload();
      } catch {
        // Keep the page usable when the status endpoint is temporarily unavailable.
      }
    }

    window.setInterval(checkMaintenanceStatus, 5000);

    function enterMaintenanceMode() {
      maintenanceScreen.hidden = false;
      sessionStorage.setItem('aiden-maintenance-mode', 'true');
      unlockButton.focus();
    }

    async function updateServerMaintenance(action, password) {
      const body = new URLSearchParams({
        maintenance_action: action,
        password
      });
      const response = await fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });
      if (!response.ok) throw new Error('maintenance request failed');
      if (action === 'unlock') sessionStorage.removeItem('aiden-maintenance-mode');
      window.location.reload();
    }

    if (serverMaintenanceMode) {
      enterMaintenanceMode();
    } else {
      sessionStorage.removeItem('aiden-maintenance-mode');
    }
    adminTrigger.addEventListener('click', () => {
      maintenanceLockModal.hidden = false;
      maintenanceLockModal.querySelector('input[name="password"]').value = '';
      maintenanceLockModal.querySelector('input[name="password"]').focus();
    });
    maintenanceLockClose.addEventListener('click', () => {
      maintenanceLockModal.hidden = true;
      adminTrigger.focus();
    });
    maintenanceLockModal.addEventListener('click', (event) => {
      if (event.target === maintenanceLockModal) {
        maintenanceLockModal.hidden = true;
        adminTrigger.focus();
      }
    });
    unlockButton.addEventListener('click', () => {
      unlockLoginModal.hidden = false;
      unlockMessage.textContent = '';
      unlockMessage.classList.remove('error');
      unlockPassword.value = '';
      unlockPassword.focus();
    });
    unlockLoginClose.addEventListener('click', () => {
      unlockLoginModal.hidden = true;
      unlockButton.focus();
    });
    unlockLoginModal.addEventListener('click', (event) => {
      if (event.target === unlockLoginModal) {
        unlockLoginModal.hidden = true;
        unlockButton.focus();
      }
    });
    unlockLoginForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      unlockMessage.textContent = '正在验证...';
      unlockMessage.classList.remove('error');
      try {
        await updateServerMaintenance('unlock', unlockPassword.value);
      } catch {
        unlockMessage.textContent = '密码错误或服务器暂时不可用。';
        unlockMessage.classList.add('error');
      }
    });

    function openAuth(mode = 'login') {
      passwordResetStatus.dataset.rejectionRequestId = '';
      if (approvedPasswordReset) {
        openApprovedPasswordReset();
        return;
      }
      if (serverUser) {
        authTitle.textContent = '账户中心';
        authTabs.forEach((tab) => { tab.hidden = true; });
        authForm.hidden = true;
        forgotPasswordTrigger.hidden = true;
        passwordResetRequestForm.hidden = true;
        passwordResetForm.hidden = true;
        passwordResetStatus.hidden = true;
        logoutPanel.hidden = false;
        loggedInName.textContent = `当前账户：${serverUser}`;
        authMessage.textContent = '你已登录，可以下载资源。';
        authMessage.classList.remove('error');
        authModal.hidden = false;
        logoutButton.focus();
        return;
      }
      authMode = mode;
      authTitle.textContent = mode === 'login' ? '登录账户' : '注册账户';
      authTabs.forEach((tab) => {
        tab.hidden = false;
        tab.classList.toggle('active', tab.dataset.authMode === mode);
      });
      authForm.hidden = false;
      forgotPasswordTrigger.hidden = mode !== 'login';
      passwordResetRequestForm.hidden = true;
      passwordResetForm.hidden = true;
      passwordResetStatus.hidden = true;
      logoutPanel.hidden = true;
      authForm.querySelector('.auth-submit').textContent = mode === 'login' ? '登录' : '注册并登录';
      authMessage.textContent = mode === 'register' ? '注册无需手机验证码。' : '';
      authMessage.classList.remove('error');
      authModal.hidden = false;
      document.querySelector('#authUsername').focus();
    }

    function openApprovedPasswordReset() {
      passwordResetStatus.dataset.rejectionRequestId = '';
      authTitle.textContent = '重置账户密码';
      authTabs.forEach((tab) => { tab.hidden = true; });
      authForm.hidden = true;
      forgotPasswordTrigger.hidden = true;
      passwordResetRequestForm.hidden = true;
      passwordResetForm.hidden = false;
      logoutPanel.hidden = true;
      passwordResetStatus.hidden = false;
      passwordResetStatus.textContent = '管理员已同意申请，请设置新密码。';
      passwordResetStatus.classList.remove('error');
      authMessage.textContent = '';
      authModal.hidden = false;
      passwordResetForm.querySelector('input[name="new_password"]').focus();
    }

    function closeAuth() {
      authModal.hidden = true;
      authMessage.textContent = '';
      accountTrigger.focus();
    }

    accountTrigger.textContent = serverUser ? `账户：${serverUser}` : '登录 / 注册';
    accountTrigger.addEventListener('click', () => openAuth('login'));
    const openApprovedPasswordResetButton = document.querySelector('#openApprovedPasswordReset');
    if (openApprovedPasswordResetButton) openApprovedPasswordResetButton.addEventListener('click', openApprovedPasswordReset);
    authClose.addEventListener('click', closeAuth);
    authModal.addEventListener('click', (event) => {
      if (event.target === authModal) closeAuth();
    });
    authTabs.forEach((tab) => tab.addEventListener('click', () => openAuth(tab.dataset.authMode)));
    forgotPasswordTrigger.addEventListener('click', () => {
      passwordResetStatus.dataset.rejectionRequestId = '';
      authTitle.textContent = '忘记密码';
      authTabs.forEach((tab) => { tab.hidden = true; });
      authForm.hidden = true;
      forgotPasswordTrigger.hidden = true;
      passwordResetRequestForm.hidden = false;
      passwordResetForm.hidden = true;
      logoutPanel.hidden = true;
      passwordResetStatus.hidden = false;
      passwordResetStatus.textContent = '';
      passwordResetStatus.classList.remove('error');
      authMessage.textContent = '';
      authModal.hidden = false;
      passwordResetUsername.focus();
    });
    document.querySelector('#cancelPasswordResetRequest').addEventListener('click', () => openAuth('login'));
    document.querySelector('#cancelPasswordReset').addEventListener('click', () => openAuth('login'));
    async function checkPasswordResetStatus() {
      try {
        const response = await fetch(`${window.location.pathname}?password-reset-status=1&t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) throw new Error('状态查询失败，请稍后重试。');
        const result = await response.json();
        if (result.status === 'approved') {
          window.clearInterval(passwordResetPoll);
          passwordResetPoll = null;
          passwordResetRequestForm.hidden = true;
          passwordResetForm.hidden = false;
          passwordResetStatus.textContent = '管理员已同意申请，请设置新密码。';
          passwordResetForm.querySelector('input[name="new_password"]').focus();
        } else if (result.status === 'rejected') {
          window.clearInterval(passwordResetPoll);
          passwordResetPoll = null;
          passwordResetStatus.textContent = `管理员已拒绝本次申请。拒绝原因：${result.reason || '未填写'}`;
          passwordResetStatus.classList.add('error');
          passwordResetStatus.dataset.rejectionRequestId = result.request_id || '';
          showRejectedPasswordResetNotice(result.request_id, result.reason);
        } else if (result.status === 'unavailable') {
          window.clearInterval(passwordResetPoll);
          passwordResetPoll = null;
          passwordResetStatus.textContent = '当前 IP 已变化，请在原网络下继续，或重新提交申请。';
          passwordResetStatus.classList.add('error');
        } else if (result.status === 'completed') {
          window.clearInterval(passwordResetPoll);
          passwordResetPoll = null;
          passwordResetStatus.textContent = '密码已重置，请使用新密码登录。';
        } else {
          passwordResetStatus.textContent = '如果账号存在，申请正在等待管理员审核……';
        }
      } catch (error) {
        passwordResetStatus.textContent = error.message || '状态查询失败，请稍后重试。';
        passwordResetStatus.classList.add('error');
      }
    }
    passwordResetRequestForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(passwordResetRequestForm);
      formData.append('auth_action', 'password_reset_request');
      const submitButton = passwordResetRequestForm.querySelector('.auth-submit');
      submitButton.disabled = true;
      passwordResetStatus.textContent = '正在提交申请……';
      passwordResetStatus.classList.remove('error');
      try {
        const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) throw new Error(result.message);
        passwordResetStatus.textContent = result.message;
        await checkPasswordResetStatus();
        if (passwordResetPoll) window.clearInterval(passwordResetPoll);
        passwordResetPoll = window.setInterval(checkPasswordResetStatus, 5000);
      } catch (error) {
        passwordResetStatus.textContent = error.message || '申请提交失败，请稍后重试。';
        passwordResetStatus.classList.add('error');
      } finally {
        submitButton.disabled = false;
      }
    });
    passwordResetForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(passwordResetForm);
      formData.append('auth_action', 'password_reset_complete');
      const submitButton = passwordResetForm.querySelector('.auth-submit');
      submitButton.disabled = true;
      passwordResetStatus.textContent = '正在更新密码……';
      passwordResetStatus.classList.remove('error');
      try {
        const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) throw new Error(result.message);
        passwordResetForm.hidden = true;
        passwordResetStatus.textContent = result.message;
        passwordResetStatus.classList.remove('error');
        approvedPasswordReset = false;
        const passwordResetNotice = document.querySelector('#passwordResetNotice');
        if (passwordResetNotice) passwordResetNotice.hidden = true;
        accountTrigger.textContent = serverUser ? `账户：${serverUser}` : '登录 / 注册';
        if (passwordResetPoll) window.clearInterval(passwordResetPoll);
        passwordResetPoll = null;
      } catch (error) {
        passwordResetStatus.textContent = error.message || '密码重置失败，请稍后重试。';
        passwordResetStatus.classList.add('error');
      } finally {
        submitButton.disabled = false;
      }
    });
    authForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(authForm);
      formData.append('auth_action', authMode);
      authMessage.textContent = '处理中...';
      authMessage.classList.remove('error');
      try {
        const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) throw new Error(result.message);
        window.location.href = pendingDownload || window.location.pathname;
      } catch (error) {
        authMessage.textContent = error.message || '操作失败，请稍后重试。';
        authMessage.classList.add('error');
      }
    });
    logoutButton.addEventListener('click', () => {
      logoutConfirm.hidden = false;
      logoutApprove.focus();
    });
    logoutCancel.addEventListener('click', () => {
      logoutConfirm.hidden = true;
      logoutButton.focus();
    });
    logoutConfirm.addEventListener('click', (event) => {
      if (event.target === logoutConfirm) {
        logoutConfirm.hidden = true;
        logoutButton.focus();
      }
    });
    logoutApprove.addEventListener('click', async () => {
      logoutConfirm.hidden = true;
      logoutButton.disabled = true;
      try {
        const body = new URLSearchParams({ auth_action: 'logout' });
        const response = await fetch(window.location.pathname, { method: 'POST', body });
        const result = await response.json();
        if (!result.success) throw new Error(result.message);
        window.location.reload();
      } catch (error) {
        authMessage.textContent = error.message || '退出登录失败，请稍后重试。';
        authMessage.classList.add('error');
        logoutButton.disabled = false;
      }
    });

    function copyText(text) {
      if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text).then(() => true);
      }
      const helper = document.createElement('textarea');
      helper.value = text;
      helper.setAttribute('readonly', '');
      helper.style.position = 'fixed';
      helper.style.opacity = '0';
      document.body.appendChild(helper);
      helper.select();
      const copied = document.execCommand('copy');
      helper.remove();
      return Promise.resolve(copied);
    }

    function closeWechat() {
      wechatModal.hidden = true;
      wechatTrigger.focus();
    }

    wechatTrigger.addEventListener('click', () => {
      wechatModal.hidden = false;
      wechatClose.focus();
    });
    wechatClose.addEventListener('click', closeWechat);
    wechatModal.addEventListener('click', (event) => {
      if (event.target === wechatModal) closeWechat();
    });
    copyWechat.addEventListener('click', async () => {
      try {
        const copied = await copyText(wechatId.textContent);
        if (!copied) throw new Error('copy failed');
        copyWechat.textContent = '已复制';
        copyWechat.classList.add('copied');
        window.setTimeout(() => {
          copyWechat.textContent = '复制微信号';
          copyWechat.classList.remove('copied');
        }, 1400);
        window.setTimeout(() => {
          closeWechat();
          window.scrollTo({ top: 0, behavior: 'smooth' });
          copyToast.hidden = false;
          window.setTimeout(() => { copyToast.hidden = true; }, 1800);
        }, 500);
      } catch {
        copyWechat.textContent = '请手动复制';
        wechatId.focus();
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(wechatId);
        selection.removeAllRanges();
        selection.addRange(range);
        window.setTimeout(() => { copyWechat.textContent = '复制微信号'; }, 1400);
      }
    });
  </script>
<div id="idy_floatdiv">
<a href="https://www.wenjuan.com/s/UZBZJvMEnUI/" target="_blank" rel="noopener noreferrer" aria-label="参与问卷调查，在新标签页打开">问卷调查</a>
</div>
</body>
</html>