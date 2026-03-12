const express = require('express');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

// Serve static files (images or custom CSS if needed) from the 'public' folder
app.use(express.static(path.join(__dirname, 'public')));

// Routes
// 1. Portal Page
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'views', 'portal.html'));
});

// 2. Login Page
app.get('/login', (req, res) => {
    res.sendFile(path.join(__dirname, 'views', 'login.html'));
});

// 3. Dashboard Page
app.get('/dashboard', (req, res) => {
    res.sendFile(path.join(__dirname, 'views', 'dashboard.html'));
});

// Start the server
app.listen(PORT, () => {
    console.log(`Server is running on http://localhost:3000`);
});