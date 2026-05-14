const express = require('express');
const path = require('path');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

// Helper to read JSON database
function readJsonDb(filename) {
    try {
        const filePath = path.join(__dirname, 'database', 'json', filename);
        if (fs.existsSync(filePath)) {
            const data = fs.readFileSync(filePath, 'utf8');
            return JSON.parse(data);
        }
        return [];
    } catch (error) {
        console.error('Error reading database:', error);
        return [];
    }
}

// Helper to write JSON database
function writeJsonDb(filename, data) {
    try {
        const filePath = path.join(__dirname, 'database', 'json', filename);
        const dir = path.dirname(filePath);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        fs.writeFileSync(filePath, JSON.stringify(data, null, 2), 'utf8');
        return true;
    } catch (error) {
        console.error('Error writing database:', error);
        return false;
    }
}

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

// API Routes

// Get all transactions
app.get('/api/transactions', (req, res) => {
    try {
        const data = readJsonDb('transactions.json');
        const transactions = Array.isArray(data) ? data : (data.transactions || []);
        res.json(transactions);
    } catch (error) {
        res.status(500).json({ error: 'Failed to fetch transactions' });
    }
});

// Get single transaction
app.get('/api/transactions/:id', (req, res) => {
    try {
        const data = readJsonDb('transactions.json');
        const transactions = Array.isArray(data) ? data : (data.transactions || []);
        const transaction = transactions.find(t => t.id == req.params.id);
        if (transaction) {
            res.json(transaction);
        } else {
            res.status(404).json({ error: 'Transaction not found' });
        }
    } catch (error) {
        res.status(500).json({ error: 'Failed to fetch transaction' });
    }
});

// Create transaction
app.post('/api/transactions', (req, res) => {
    try {
        const data = readJsonDb('transactions.json');
        const transactions = Array.isArray(data) ? data : (data.transactions || []);
        
        // Validate request
        if (!req.body || Object.keys(req.body).length === 0) {
            return res.status(400).json({ error: 'Invalid request data' });
        }
        
        // Create new transaction
        const newTransaction = {
            id: Math.max(...transactions.map(t => t.id || 0), 0) + 1,
            ...req.body,
            createdAt: new Date().toISOString()
        };
        
        transactions.push(newTransaction);
        
        // Write back with the proper structure
        if (writeJsonDb('transactions.json', { transactions })) {
            res.status(201).json(newTransaction);
        } else {
            res.status(500).json({ error: 'Failed to create transaction' });
        }
    } catch (error) {
        console.error('Error creating transaction:', error);
        res.status(500).json({ error: 'Failed to create transaction: ' + error.message });
    }
});

// Update transaction
app.put('/api/transactions/:id', (req, res) => {
    try {
        const data = readJsonDb('transactions.json');
        const transactions = Array.isArray(data) ? data : (data.transactions || []);
        const index = transactions.findIndex(t => t.id == req.params.id);
        
        if (index === -1) {
            return res.status(404).json({ error: 'Transaction not found' });
        }
        
        transactions[index] = { ...transactions[index], ...req.body, id: parseInt(req.params.id) };
        
        if (writeJsonDb('transactions.json', { transactions })) {
            res.json(transactions[index]);
        } else {
            res.status(500).json({ error: 'Failed to update transaction' });
        }
    } catch (error) {
        res.status(500).json({ error: 'Failed to update transaction' });
    }
});

// Delete transaction
app.delete('/api/transactions/:id', (req, res) => {
    try {
        const data = readJsonDb('transactions.json');
        let transactions = Array.isArray(data) ? data : (data.transactions || []);
        const index = transactions.findIndex(t => t.id == req.params.id);
        
        if (index === -1) {
            return res.status(404).json({ error: 'Transaction not found' });
        }
        
        transactions.splice(index, 1);
        
        if (writeJsonDb('transactions.json', { transactions })) {
            res.json({ message: 'Transaction deleted successfully' });
        } else {
            res.status(500).json({ error: 'Failed to delete transaction' });
        }
    } catch (error) {
        res.status(500).json({ error: 'Failed to delete transaction' });
    }
});

// Start the server
app.listen(PORT, () => {
    console.log(`Server is running on http://localhost:3000`);
});