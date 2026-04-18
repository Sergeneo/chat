<?php
$host = "MySQL-8.4";
$db = "chat";
$user = "root";
$pass = "";

$conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

session_start();

$auth = !empty($_SESSION['user']);
$user = $_SESSION['user'] ?? null;

if ($auth && $user) {
	$stmt = $conn->prepare("SELECT id, name, email, is_admin FROM users WHERE id=? LIMIT 1");
	$stmt->execute([$user['id']]);
	$freshUser = $stmt->fetch(PDO::FETCH_ASSOC);

	if ($freshUser) {
		$_SESSION['user'] = $freshUser;
		$user = $freshUser;
	} else {
		unset($_SESSION['user']);
		$auth = false;
		$user = null;
	}
}

if (isset($_POST['register'])) {
	if ($auth) {
		unset($_SESSION['user']);
		header("Location: /");
		exit;
	}
	$name = trim(strip_tags($_POST['name']));
	$post_pass = trim($_POST['password']);

	if (!$name or !$post_pass) {
		die("Please fill in the required fields, <a href='/?register'>go back</a>");
		exit;
	}

	$pass = password_hash($post_pass, PASSWORD_BCRYPT);

	$stmt = $conn->prepare("SELECT id FROM users WHERE name=?");
	$stmt->execute([$name]);
	if ($stmt->fetch()) {
		die("The name is already in use, <a href='/?register'>go back</a>");
		exit;
	}

	$stmt = $conn->prepare("INSERT INTO users (name,password) VALUES (?,?)");
	$stmt->execute([$name,$pass]);

	$userId = $conn->lastInsertId();
	$stmt = $conn->prepare("SELECT id, name FROM users WHERE id=?");
	$stmt->execute([$userId]);
	$user = $stmt->fetch(PDO::FETCH_ASSOC);

	$_SESSION['user'] = $user;

	session_regenerate_id(true);

	header("Location: /");
	exit;
}

if (isset($_POST['login'])) {
	if ($auth) {
		unset($_SESSION['user']);
		header("Location: /");
		exit;
	}
	$stmt = $conn->prepare("SELECT * FROM users WHERE name=?");
	$stmt->execute([$_POST['name']]);
	$user = $stmt->fetch();

	if ($user && password_verify($_POST['password'], $user['password'])) {
		$_SESSION['user'] = $user;
		header("Location: /");
		exit;
	}
}

if (isset($_GET['ajax']) and $auth) {
	if ($_GET['ajax'] === 'load_dialogs') {
		$currentUser = $user['id'];

		$stmt = $conn->prepare("SELECT id, name, last_activity FROM users WHERE id != ?");
		$stmt->execute([$currentUser]);
		$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($users as &$u) {
			$dialogId = getDialogId($conn, $currentUser, $u['id']);
			$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM messages WHERE dialog_id=? AND user_id != ? AND is_read=0");
			$stmt->execute([$dialogId, $currentUser]);
			$cnt = $stmt->fetch(PDO::FETCH_ASSOC);
			$u['new'] = $cnt['cnt'] ?? 0;
			$u['status'] = getStatus($u['last_activity']);
			$u['dialog_id'] = $dialogId;
		}

		echo json_encode($users);
		exit;
	}

	if ($_GET['ajax'] === 'load_messages') {
		$userId = $user['id'];
		$dialog = (int)($_GET['dialog'] ?? 0);
		$offset = (int)($_GET['offset'] ?? 0);
		$lastId = (int)($_GET['last_id'] ?? 0);
		$limit = 30;

		if (!$dialog) {
				http_response_code(403);
				echo json_encode(['error'=>'No dialog selected']);
				exit;
		}

		$stmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE dialog_id=? AND user_id != ?");
		$stmt->execute([$dialog, $userId]);

		if ($lastId > 0) {
				$stmt = $conn->prepare("
						SELECT m.id, m.user_id, m.message, m.file, m.created_at, u.name 
						FROM messages m
						JOIN users u ON u.id = m.user_id
						WHERE dialog_id=? AND m.id > ?
						ORDER BY m.id ASC
				");
				$stmt->execute([$dialog, $lastId]);
		} else {
				$stmt = $conn->prepare("
						SELECT m.id, m.user_id, m.message, m.file, m.created_at, u.name 
						FROM messages m
						JOIN users u ON u.id = m.user_id
						WHERE dialog_id=?
						ORDER BY m.id DESC
						LIMIT $limit OFFSET $offset
				");
				$stmt->execute([$dialog]);
		}

		echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
		exit;
	}

	if ($_GET['ajax'] === 'send') {
		$userId = $user['id'];
		$dialog = (int)($_POST['dialog'] ?? 0);;
		$msg = trim(strip_tags($_POST['message'] ?? ''));

		$fileName = null;

		if (!empty($_FILES['file']['name'])) {
			$file = $_FILES['file'];
			if ($file['size'] > 4 * 1024 * 1024) {
					exit(json_encode(['error' => 'The file is too large, maximum 4 MB']));
			}
			$allowedTypes = ['image/jpeg','image/png','image/gif','image/webp'];
			$allowedExt = ['jpg','jpeg','png','gif','webp'];

			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mimeType = finfo_file($finfo, $file['tmp_name']);
			finfo_close($finfo);

			$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			if (!in_array($mimeType, $allowedTypes) || !in_array($ext, $allowedExt)) {
				exit(json_encode(['error' => 'Only JPG, PNG, GIF, and WEBP images are allowed']));
			}

			$fileName = time() . "_" . bin2hex(random_bytes(5)) . "." . $ext;
			$uploadDir = __DIR__ . "/uploads/";
			if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

			if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
				exit(json_encode(['error' => 'File upload error']));
			}
		}

		$stmt = $conn->prepare("INSERT INTO messages (dialog_id,user_id,message,file) VALUES (?,?,?,?)");
		$stmt->execute([$dialog, $userId, $msg, $fileName]);
		exit;
	}

	if ($_GET['ajax'] === 'delete') {
		$id = (int) $_GET['id'];
		$user = $user['id'];

		$stmt = $conn->prepare("SELECT file FROM messages WHERE id=? AND user_id=?");
		$stmt->execute([$id,$user]);
		$msg = $stmt->fetch();

		if ($msg['file']) unlink(__DIR__ ."/uploads/". $msg['file']);

		$conn->prepare("DELETE FROM messages WHERE id=? AND user_id=?")->execute([$id,$user]);
		exit;
	}

	if ($_GET['ajax'] === 'update_activity') {
		$user_id = $user['id'];
		$time = time();

		$stmt = $conn->prepare("UPDATE users SET last_activity=? WHERE id=?");
		$stmt->execute([$time, $user_id]);
		exit;
	}

	if ($_GET['ajax'] === 'logout') {
		unset($_SESSION['user']);
		header("Location: /");
		exit;
	}
}

function getDialogId($conn, $user1, $user2){
	// always put the smaller ID first so that uniqueness works
	$ids = [$user1, $user2];
	sort($ids);
	$stmt = $conn->prepare("SELECT id FROM dialogs WHERE user1_id=? AND user2_id=?");
	$stmt->execute($ids);
	$dialog = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($dialog) return $dialog['id'];

	// if the dialog doesn’t exist, create it
	$stmt = $conn->prepare("INSERT INTO dialogs (user1_id,user2_id) VALUES (?,?)");
	$stmt->execute($ids);
	return $conn->lastInsertId();
}

function getStatus($last_activity){
	$diff = time() - $last_activity;

	if ($diff < 60) return "<span class='online' title='🟢 Online'></span>";
	if ($diff < 300) return "<span class='offline' title='Last seen just now'></span>";
	if ($diff < 600) return "<span class='offline' title='Last seen recently'></span>";

	$minutes = floor($diff / 60);
	return "<span class='offline' title='Last seen $minutes minutes ago'></span>";
}

if ($auth && isset($_GET['admin']) && $user['is_admin']==1) {
	if (isset($_GET['ajax'])) {
		if ($_GET['ajax'] === 'delete_user') {
			$uid = (int)$_POST['id'];
			$conn->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
			$conn->prepare("DELETE FROM messages WHERE user_id=?")->execute([$uid]);
			exit(json_encode(['success'=>true]));
		}
		if ($_GET['ajax'] === 'delete_message') {
			$mid = (int)$_POST['id'];
			$conn->prepare("DELETE FROM messages WHERE id=?")->execute([$mid]);
			exit(json_encode(['success'=>true]));
		}
	}

	$usersPerPage = 10;
	$messagesPerPage = 20;
	$usersPage = max(1, (int)($_GET['users_page'] ?? 1));
	$messagesPage = max(1, (int)($_GET['messages_page'] ?? 1));
	$usersOffset = ($usersPage - 1) * $usersPerPage;
	$messagesOffset = ($messagesPage - 1) * $messagesPerPage;

	$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
	$totalMessages = $conn->query("SELECT COUNT(*) FROM messages")->fetchColumn();

	$users = $conn->query("SELECT id, name, email, last_activity, is_admin, created_at 
		FROM users ORDER BY id ASC LIMIT $usersPerPage OFFSET $usersOffset")->fetchAll(PDO::FETCH_ASSOC);

	$messages = $conn->query("
		SELECT m.id, m.user_id, m.dialog_id, m.message, m.created_at, u.name, m.file
		FROM messages m
		JOIN users u ON u.id = m.user_id
		ORDER BY m.id DESC
		LIMIT $messagesPerPage OFFSET $messagesOffset
	")->fetchAll(PDO::FETCH_ASSOC);

	function formatDate($ts) { return date("d.m.Y H:i:s", strtotime($ts)); }

	if (!isset($_GET['active-tab'])) $_GET['active-tab'] = 'users';
	?>
	<!DOCTYPE html>
	<html data-bs-theme="light">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Admin Panel</title>
		<link rel="stylesheet" href="/assets/css/fontawesome.min.css">
		<link rel="stylesheet" href="/assets/css/bootstrap.min.css">
		<link rel="stylesheet" href="/assets/css/style.css?v=<?= time() ?>">
	</head>
	<body>
	<nav class="navbar navbar-expand-lg border-bottom bg-body-tertiary py-1">
		<div class="container-fluid">
			<a class="navbar-brand" href="/"><img src="/assets/images/logotype.png" width="26" height="26"> Chat</a>
			<div class="d-flex align-items-center">
				<div class="d-flex align-items-center text-secondary border-end pe-3">
					<i class="fa-regular fa-sun me-2"></i>
					<label class="switch">
						<input type="checkbox" id="themeSwitch" onchange="toggleTheme()">
						<span class="slider"></span>
					</label>
					<i class="fa-regular fa-moon ms-2"></i>
				</div>
				<div class="ps-3">
					<ul class="navbar-nav">
						<?php if ($user) { ?>
						<li class="nav-item dropdown">
							<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= $user['name'] ?></a>
							<ul class="dropdown-menu dropdown-menu-end">
								<?php if ($user['is_admin']) { ?><li><a class="dropdown-item text-danger" href="/?admin">Admin Panel</a></li><?php } ?>
								<li><a class="dropdown-item" href="/?ajax=logout">Log out</a></li>
							</ul>
						</li>
						<?php } else { ?>
						<li class="nav-item"><a class="nav-link" aria-current="page" href="/?login">Log in / Register</a></li>
						<?php } ?>
					</ul>
				</div>
			</div>
		</div>
	</nav>
	<div class="container py-4">
		<h3 class="mb-3">Chat admin panel</h3>
		<ul class="nav nav-tabs" id="adminTabs" role="tablist">
			<li class="nav-item"><button class="nav-link <?= $_GET['active-tab'] == 'users' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#users">Users</button></li>
			<li class="nav-item"><button class="nav-link <?= $_GET['active-tab'] == 'messages' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#messages">Messages</button></li>
		</ul>
		<div class="tab-content mt-3">
			<div class="tab-pane fade <?= $_GET['active-tab'] == 'users' ? 'show active' : '' ?>" id="users">
				<table class="table table-sm table-striped table-bordered align-middle">
					<thead class="table-light">
						<tr>
							<th>ID</th>
							<th>Name</th>
							<th>Email</th>
							<th>Last activity</th>
							<th>Registration date</th>
							<th>Admin</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($users as $u): ?>
							<tr>
								<td><?= $u['id'] ?></td>
								<td><?= htmlspecialchars($u['name']) ?></td>
								<td><?= htmlspecialchars($u['email']) ?></td>
								<td><?= $u['last_activity'] ? date("d.m.Y H:i",$u['last_activity']) : "-" ?></td>
								<td><?= $u['created_at'] ? date("d.m.Y H:i", strtotime($u['created_at'])) : "-" ?></td>
								<td><?= $u['is_admin'] ? "Yes" : "No" ?></td>
								<td>
									<?php if($u['id'] != $user['id']): ?>
										<button class="btn btn-sm btn-danger delete-user" data-id="<?= $u['id'] ?>">Delete</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<nav>
					<ul class="pagination">
						<?php for($i=1;$i<=ceil($totalUsers/$usersPerPage);$i++): ?>
							<li class="page-item <?= $i==$usersPage?'active':'' ?>">
								<a class="page-link" href="?admin&users_page=<?= $i ?>&messages_page=<?= $messagesPage ?>&active-tab=users"><?= $i ?></a>
							</li>
						<?php endfor; ?>
					</ul>
				</nav>
			</div>
			<div class="tab-pane fade <?= $_GET['active-tab'] == 'messages' ? 'show active' : '' ?>" id="messages">
				<table class="table table-sm table-striped table-bordered align-middle">
					<thead class="table-light">
						<tr>
							<th>ID</th>
							<th>User</th>
							<th>Dialog</th>
							<th>Message</th>
							<th>Time</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($messages as $m): ?>
							<tr>
								<td><?= $m['id'] ?></td>
								<td><?= htmlspecialchars($m['name']) ?></td>
								<td><?= $m['dialog_id'] ?></td>
								<td>
									<div>
										<?= $m['file'] ? "<img src='/uploads/{$m['file']}' class='my-1 mw-100 rounded chat-image' style='cursor:pointer' data-bs-toggle='modal' data-bs-target='#imageModal'
											data-src='/uploads/{$m['file']}'>" : '' ?>
									</div>
									<?= htmlspecialchars($m['message']) ?>
								</td>
								<td><?= formatDate($m['created_at']) ?></td>
								<td><button class="btn btn-sm btn-danger delete-message" data-id="<?= $m['id'] ?>">Delete</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<nav>
					<ul class="pagination">
						<?php for($i=1;$i<=ceil($totalMessages/$messagesPerPage);$i++): ?>
							<li class="page-item <?= $i==$messagesPage?'active':'' ?>">
								<a class="page-link" href="?admin&messages_page=<?= $i ?>&users_page=<?= $usersPage ?>&active-tab=messages"><?= $i ?></a>
							</li>
						<?php endfor; ?>
					</ul>
				</nav>
			</div>
		</div>
	</div>
	<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<div class="modal-content p-3 border-0 bg-dark bg-opacity-75">
				<div class="modal-body p-2 text-center position-relative">
					<button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" data-bs-dismiss="modal"></button>
					<img id="modalImage" src="" class="img-fluid rounded" style="max-height: 80vh; width: auto;">
				</div>
			</div>
		</div>
	</div>
	<script src="/assets/js/bootstrap.bundle.js"></script>
	<script>
	document.querySelectorAll('.delete-user').forEach(btn=>{
		btn.onclick = ()=>{
			if (!confirm('Delete the user and all their messages?')) return;
			fetch('?admin&ajax=delete_user',{method:'POST',body:new URLSearchParams({id:btn.dataset.id})})
			.then(()=>btn.closest('tr').remove());
		};
	});
	document.querySelectorAll('.delete-message').forEach(btn=>{
		btn.onclick = ()=>{
			if (!confirm('Delete the message?')) return;
			fetch('?admin&ajax=delete_message',{method:'POST',body:new URLSearchParams({id:btn.dataset.id})})
			.then(()=>btn.closest('tr').remove());
		};
	});
	function toggleTheme(){
		const html = document.documentElement;
		const current = html.getAttribute('data-bs-theme');
		const next = current === 'dark' ? 'light' : 'dark';
		html.setAttribute('data-bs-theme', next);
		localStorage.setItem('theme', next);
	}
	document.addEventListener('DOMContentLoaded', () => {
		const theme = localStorage.getItem('theme') || 'light';
		document.documentElement.setAttribute('data-bs-theme', theme);
		const switchEl = document.querySelector('#themeSwitch');
		if (switchEl) {
			switchEl.checked = (theme === 'dark');
		}
	});
	const imageModal = document.getElementById('imageModal');
	const modalImage = document.getElementById('modalImage');
	document.addEventListener('click', (e) => {
		const img = e.target.closest('.chat-image');
		if (!img) return;
		modalImage.src = img.dataset.src;
	});
	</script>
	</body>
	</html>
<?php
exit;
}
?>

<!DOCTYPE html>
<html data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chat</title>
<link rel="shortcut icon" href="/assets/images/favicon.ico">
<link rel="stylesheet" href="/assets/css/fontawesome.min.css">
<link rel="stylesheet" href="/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= time() ?>">
<script>
window.currentUserId = <?= $user ? $user['id'] : 0 ?>;
</script>
</head>
<body>
<nav class="navbar navbar-expand-lg border-bottom bg-body-tertiary py-1">
	<div class="container-fluid">
		<a class="navbar-brand" href="/"><img src="/assets/images/logotype.png" width="26" height="26"> Chat</a>
		<div class="d-flex align-items-center">
			<div class="d-flex align-items-center text-secondary border-end pe-3">
				<i class="fa-regular fa-sun me-2"></i>
				<label class="switch">
					<input type="checkbox" id="themeSwitch" onchange="toggleTheme()">
					<span class="slider"></span>
				</label>
				<i class="fa-regular fa-moon ms-2"></i>
			</div>
			<div class="ps-3">
				<ul class="navbar-nav">
					<?php if ($user) { ?>
					<li class="nav-item dropdown">
						<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= $user['name'] ?></a>
						<ul class="dropdown-menu dropdown-menu-end">
							<?php if ($user['is_admin']) { ?><li><a class="dropdown-item text-danger" href="/?admin">Admin Panel</a></li><?php } ?>
							<li><a class="dropdown-item" href="/?ajax=logout">Log out</a></li>
						</ul>
					</li>
					<?php } else { ?>
					<li class="nav-item"><a class="nav-link" aria-current="page" href="/?login">Log in / Register</a></li>
					<?php } ?>
				</ul>
			</div>
		</div>
	</div>
</nav>

<?php if (isset($_GET['register']) and !$auth) { ?>
<form method="POST" class="container text-center mt-5">
	<div class="container-login">
		<h3>Registration</h3>
		<input type="hidden" name="register" value="register" required>
		<input name="name" placeholder="Name" class="form-control mb-2" required>
		<input name="password" type="password" placeholder="Password" class="form-control mb-3" required>
		<button class="btn btn-primary mb-3">Registration</button>
		<div>
			<a href="/?login">Log in</a>
		</div>
	</div>
</form>

<?php } elseif (isset($_GET['login']) or !$auth) { ?>
<form method="POST" class="container text-center mt-5">
	<div class="container-login">
		<h3>Log in</h3>
		<input type="hidden" name="login" value="login" required>
		<input name="name" placeholder="Name" class="form-control mb-2" required>
		<input name="password" type="password" class="form-control mb-3" placeholder="Password" required>
		<button class="btn btn-success mb-3">Login</button>
		<div>
			<a href="/?register">Registration</a>
		</div>
	</div>
</form>

<?php } else { ?>
<div class="container-fluid container-chat">
	<div class="row">
		<div class="col border-end container-dialogs px-0" id="dialogs"></div>
		<div class="col d-flex flex-column pb-2 container-chat">
			<div id="chat" class="flex-grow-1 overflow-auto">
				<p class="text-center text-secondary select-dialog"><i class="fa-solid fa-hand-point-left"></i> Select a conversation partner</p>
			</div>
			<form id="sendForm" class="d-flex align-items-center gap-2">
				<label for="file" class="btn btn-secondary mb-0 position-relative">
					<i class="fa fa-paperclip"></i>
					<div id="previewContainer"></div>
				</label>
				<input type="file" id="file" accept=".jpg,.jpeg,.png,.gif,.webp" hidden>
				<input id="message" class="form-control" placeholder="Enter a message..." autocomplete="off">
				<button class="btn btn-primary">
					<i class="fa fa-paper-plane"></i>
				</button>
			</form>
		</div>
	</div>
</div>
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content p-3 border-0 bg-dark bg-opacity-75">
			<div class="modal-body p-2 text-center position-relative">
				<button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" data-bs-dismiss="modal"></button>
				<img id="modalImage" src="" class="img-fluid rounded" style="max-height: 80vh; width: auto;">
			</div>
		</div>
	</div>
</div>
<?php } ?>

<script src="/assets/js/bootstrap.bundle.js"></script>
<script src="/assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>