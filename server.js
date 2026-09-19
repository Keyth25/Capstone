const { spawn } = require('child_process');
const { existsSync } = require('fs');

const HOST = process.env.HOST || '127.0.0.1';
const PORT = process.env.PORT || 8000;
const PHP_BIN =
  process.env.PHP_BIN ||
  (existsSync('C:\\xampp\\php\\php.exe') ? 'C:\\xampp\\php\\php.exe' : 'php');

const server = spawn(
  PHP_BIN,
  ['-S', `${HOST}:${PORT}`, '-t', __dirname],
  { stdio: 'inherit' }
);

console.log(`Matutum PlotNav running at http://${HOST}:${PORT}/landing.php`);
console.log('Press Ctrl+C to stop.');

server.on('error', (err) => {
  if (err.code === 'ENOENT') {
    console.error('PHP not found. Install PHP or set the PHP_BIN env var to your php.exe path.');
  } else {
    console.error(err);
  }
  process.exit(1);
});

server.on('exit', (code) => process.exit(code ?? 0));
