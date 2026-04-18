# 💬 PHP Chat Application

A simple real-time chat application built with pure PHP, MySQL, and JavaScript (AJAX).

This project demonstrates a lightweight messaging system with user authentication, file uploads, and an admin panel.

---

## 🚀 Features

### 👤 User System
- Registration & login (session-based)
- Password hashing using `bcrypt`
- Unique username validation
- Persistent session handling

### 💬 Chat System
- Private dialogs between users
- Automatic dialog creation
- Real-time message loading via AJAX
- Infinite scroll (message pagination)
- New message polling

### 📩 Messaging Features
- Send text messages
- Upload images (JPG, PNG, GIF, WEBP)
- File size limit (4MB)
- Message timestamps
- Delete own messages

### 🟢 User Status
- Online indicator
- Last seen tracking
- Activity auto-update

### 🧰 Admin Panel
Accessible via `/?admin` (admin only)

- View all users
- Delete users (with all messages)
- View all messages
- Delete any message
- Pagination for users & messages

### 🎨 UI/UX
- Responsive layout (Bootstrap)
- Dark / Light mode toggle
- Image preview modal
- Clean chat interface

---

## 🔐 Demo Accounts

You can use the following test accounts:

| Username | Password |
|----------|----------|
| admin    | 12345    |
| Sergey   | 12345    |

---

## 📸 Screenshots

### Chat Interface

### Admin Panel


---

## ⚙️ Installation

1. Import the SQL dump file `chat.sql` located in the project root:
2. Configure connection, edit file `index.php`:
```
$host = "localhost";
$db = "chat";
$user = "root";
$pass = "";
```
