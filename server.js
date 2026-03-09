// Import the http module
const http = require("express");
const port = 3000;
const app = express();

// First middleware
app.use((req, res, next) => {
  console.log('Middleware 1: This always runs');
  next();
});

// Second middleware
app.use((req, res, next) => {
  console.log('Middleware 2: This also always runs');
  next();
});

// Route handler
app.get('/', (req, res) => {
  res.sendFile(__dirname + '/public/login.html');
});

app.get('/error', (req, res) => {
  res.sendFile(__dirname + '/public/error.html');
});


// Listen on port 3000
app.listen(port, () => {
    console.log("Server is running on http://localhost:"+port);
});