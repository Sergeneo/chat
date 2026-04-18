// auto-update of new messages, 1000 is 1 second, if less than 1000 then disabled
let ajax_load_messages = 5000;

// automatic status update (online, offline), 1000 is 1 second, if less than 1000 then disabled
let ajax_update_activity = 15000;

// auto-update of the dialog list, 1000 is 1 second, if less than 1000 then disabled
let ajax_update_dialogs = 10000;

let dialog = 0;
let offset = 0;
let selectedDialogEl = null;
let lastId = 0;
let loading = false;
let dialogsState = {};

function formatDate(dateStr) {
	const date = new Date(dateStr);
	const now = new Date();

	const isToday = date.toDateString() === now.toDateString();
	const yesterday = new Date(now);
	yesterday.setDate(now.getDate() - 1);
	const isYesterday = date.toDateString() === yesterday.toDateString();

	const pad = n => n.toString().padStart(2, '0');
	const time = `${pad(date.getHours())}:${pad(date.getMinutes())}`;

	if (isToday) return time;
	if (isYesterday) return `Yesterday at ${time}`;
	return `${pad(date.getDate())}.${pad(date.getMonth()+1)}.${date.getFullYear()} ${time}`;
}

function loadMessages(type = 'init') {
	if (!dialog || loading) return;
	loading = true;

	const chat = document.getElementById('chat');

	const wasAtBottom = chat.scrollTop + chat.clientHeight >= chat.scrollHeight - 5;
	const oldScrollHeight = chat.scrollHeight;

	let url = `/index.php?ajax=load_messages&dialog=${dialog}`;

	if (type === 'old') {
		url += `&offset=${offset}`;
	} else if (type === 'new') {
		url += `&last_id=${lastId}`;
	}

	fetch(url)
	.then(r => r.json())
	.then(data => {
		if (!data.length) {
			loading = false;
			return;
		}

		if (type == 'init') data.reverse();

		data.forEach(m => {
			if (chat.querySelector(`[data-id="${m.id}"]`)) return;

			const div = document.createElement('div');
			div.dataset.id = m.id;
			div.className = m.user_id == window.currentUserId ? 'message me' : 'message other';

			div.innerHTML = `
				<a href="#" class="message-delete" data-id="${m.id}"><i class="fa-solid fa-trash-can"></i></a>
				<div><b>${m.name}</b></div>
				<div>
					${m.file ? `
					<img 
						src="/uploads/${m.file}" 
						class="my-1 mw-100 rounded chat-image" 
						style="cursor:pointer"
						data-bs-toggle="modal"
						data-bs-target="#imageModal"
						data-src="/uploads/${m.file}"
					>
					` : ''}
				</div>
				<div>${m.message}</div>
				<div class="text-end">
					<span class="message-date" title="${m.created_at}">${formatDate(m.created_at)}</span>
				</div>
			`;

			// new messages
			if (type === 'new' || type === 'init') {
				chat.append(div);
				lastId = m.id;

				if (!wasAtBottom) {
					showNewMessageBadge();
				}
			} else {
				chat.prepend(div);
			}
		});

		if (type === 'old') {
			offset += data.length;
		}

		if (type === 'init') {
			requestAnimationFrame(async () => {
				await new Promise(r => requestAnimationFrame(r));
				chat.scrollTop = chat.scrollHeight;
			});
			lastId = chat.lastElementChild?.dataset?.id || 0;
		}
		else if (type === 'old') {
			chat.scrollTop = chat.scrollHeight - oldScrollHeight;
		}
		else if (type === 'new') {
			if (wasAtBottom) {
				chat.scrollTop = chat.scrollHeight;
			}
		}

		loading = false;
	});
}
loadMessages('init')

if (ajax_load_messages >= 1000) {
	setInterval(()=>loadMessages('new'), ajax_load_messages);
}

const chatEl = document.getElementById('chat');
if(chatEl) {
	chatEl.onscroll = function() {
		if(this.scrollTop < 50) loadMessages('old');
	};
}

function showNewMessageBadge() {
	let badge = document.getElementById('newMsgBadge');
	if (!badge) {
		badge = document.createElement('div');
		badge.id = 'newMsgBadge';
		badge.innerText = 'New messages ↓';
		badge.style = `
			position: sticky;
			bottom: 10px;
			background: #1e9f00;
			color: #fff;
			padding: 5px 10px;
			border-radius: 10px;
			cursor: pointer;
			text-align: center;
			margin: 0 auto;
			max-width: 180px;
		`;

		badge.onclick = () => {
			const chat = document.getElementById('chat');
			chat.scrollTop = chat.scrollHeight;
			badge.remove();
		};

		document.getElementById('chat').append(badge);
	}
}

if (document.getElementById('sendForm')) {
	document.getElementById('sendForm').onsubmit = e=>{
		e.preventDefault();
		if (!dialog) {
			alert('Select a conversation partner');
			return false;
		}
		if (!message.value.trim() && !file.files[0]) {
			return false;
		}

		let formData = new FormData();
		formData.append('message', message.value.trim());
		formData.append('dialog', dialog);
		formData.append('file', file.files[0]);

		fetch('/index.php?ajax=send',{method:'POST',body:formData})
		.then(() => {
			loadMessages('new');
			const chat = document.getElementById('chat');
			chat.scrollTop = chat.scrollHeight;
		});

		message.value='';
	};
}

if (document.getElementById("message")) {
	document.getElementById("message").addEventListener("keypress", e=>{
		if(e.key==="Enter"){
			e.preventDefault();
			document.getElementById("sendForm").onsubmit(e);
		}
	});
}

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

if (ajax_update_activity >= 1000) {
	setInterval(()=>{
		fetch('/index.php?ajax=update_activity');
	}, ajax_update_activity);
}
fetch('/index.php?ajax=update_activity');

function loadDialogs(){
	fetch('/index.php?ajax=load_dialogs')
	.then(r=>r.json())
	.then(data=>{
		const container = document.getElementById('dialogs');
		container.innerHTML = '';

		data.forEach(u=>{
			const prevCount = dialogsState[u.dialog_id] || 0;

			if (u.new > prevCount) {
				//let audio = new Audio('/assets/sound.mp3');
				//audio.play();
			}

			dialogsState[u.dialog_id] = u.new;

			let div = document.createElement('div');
			div.className = 'dialog-item py-2 px-3 border-bottom';

			if (u.dialog_id == dialog) {
				div.classList.add('active');
				selectedDialogEl = div;
			}

			div.innerHTML = `<b>${u.name}</b> 
				<span class="status-dot ms-1">${u.status}</span>
				${u.new>0 ? `<span class="new-msg-count">${u.new}</span>` : ''}`;

			div.onclick = ()=> {
				dialog = u.dialog_id;
				offset = 0;
				document.getElementById('chat').innerHTML = '';
				loadMessages('init');
				document.querySelector('.select-dialog')?.remove();

				if(selectedDialogEl) selectedDialogEl.classList.remove('active');
				div.classList.add('active');
				selectedDialogEl = div;
			};

			container.appendChild(div);
		});
	});
}

if (ajax_update_dialogs >= 1000) {
	setInterval(loadDialogs, ajax_update_dialogs);
}

document.addEventListener('DOMContentLoaded', loadDialogs);

document.addEventListener('click', function(e){
	const btn = e.target.closest('.message-delete');
	if (!btn) return;
	e.preventDefault();
	const id = btn.dataset.id;
	if (!confirm('Delete the message?')) return;
	fetch(`/index.php?ajax=delete&id=${id}`)
	.then(() => {
		const msgEl = btn.closest('.message');
		if (msgEl) msgEl.remove();
	});
});

const fileInput = document.getElementById('file');
const maxSize = 4 * 1024 * 1024; // 4MB

fileInput.addEventListener('change', () => {
	const file = fileInput.files[0];
	if (!file) return;
	if (file.size > maxSize) {
		alert('The file is too large, maximum 4 MB');
		fileInput.value = '';
		return;
	}
	if (!file.type.startsWith('image/')) {
		alert('Only image files are allowed');
		fileInput.value = '';
		return;
	}

	const img = document.createElement('img');
	img.src = URL.createObjectURL(file);
	img.style.maxWidth = '100%';
	img.style.maxHeight = '100%';
	img.style.border = '1px solid #ccc';
	img.style.borderRadius = '5px';
	img.style.marginTop = '5px';
	img.style.position = 'absolute';
	img.style.top = '-4px';
	img.style.left = '0px';
	img.style.zIndex = '2';
	previewContainer.appendChild(img);
	img.onload = () => URL.revokeObjectURL(img.src);
});

const imageModal = document.getElementById('imageModal');
const modalImage = document.getElementById('modalImage');

document.addEventListener('click', (e) => {
	const img = e.target.closest('.chat-image');
	if (!img) return;
	modalImage.src = img.dataset.src;
});
