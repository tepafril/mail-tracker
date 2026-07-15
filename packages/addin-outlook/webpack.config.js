/* eslint-env node */
const path = require('path');
const HtmlWebpackPlugin = require('html-webpack-plugin');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const webpack = require('webpack');
const devCerts = require('office-addin-dev-certs');

// Load packages/addin-outlook/.env into process.env so the DefinePlugin values below
// (BACKEND_BASE_URL, ENTRA_CLIENT_ID, API_SCOPE) actually reflect it. Shell env wins.
require('dotenv').config({ path: path.resolve(__dirname, '.env') });

const DEV_SERVER_PORT = 3000;

module.exports = async (env, argv) => {
  const isProd = argv.mode === 'production';
  // Only load the trusted dev cert when actually serving (not for production builds).
  const serving = process.env.WEBPACK_SERVE === 'true';
  const httpsOptions = serving ? await devCerts.getHttpsServerOptions() : undefined;

  return {
    devtool: isProd ? false : 'source-map',
    entry: {
      // Task pane UI (sign-in, contact match, timeline, manual log).
      taskpane: './src/taskpane/taskpane.ts',
      // Event-runtime bundle for OnMessageSend. Emitted as commands.js AND hosted by
      // commands.html: classic Outlook on Windows loads the .js directly (JSRuntime),
      // while the WebView runtimes (OWA / new Outlook on Windows / Mac) load the HTML.
      commands: './src/commands/commands.ts',
    },
    output: {
      path: path.resolve(__dirname, 'dist'),
      filename: '[name].js',
      // When hosted under a subpath (e.g. https://host/addin/), set PUBLIC_PATH=/addin/
      // so HtmlWebpackPlugin emits <script src="/addin/taskpane.js">. Defaults to '/'
      // for the dev server and root hosting.
      publicPath: process.env.PUBLIC_PATH || '/',
      clean: true,
    },
    resolve: {
      extensions: ['.ts', '.js'],
    },
    module: {
      rules: [
        {
          test: /\.ts$/,
          use: [{ loader: 'ts-loader', options: { transpileOnly: true } }],
          exclude: /node_modules/,
        },
      ],
    },
    plugins: [
      new HtmlWebpackPlugin({
        filename: 'taskpane.html',
        template: './src/taskpane/taskpane.html',
        chunks: ['taskpane'],
      }),
      new HtmlWebpackPlugin({
        filename: 'commands.html',
        template: './src/commands/commands.html',
        chunks: ['commands'],
      }),
      new CopyWebpackPlugin({
        patterns: [{ from: 'assets', to: 'assets', noErrorOnMissing: true }],
      }),
      // Build-time config injected from the environment (see src/config.ts).
      new webpack.DefinePlugin({
        'process.env.BACKEND_BASE_URL': JSON.stringify(process.env.BACKEND_BASE_URL || 'https://localhost:3000'),
        'process.env.ENTRA_CLIENT_ID': JSON.stringify(process.env.ENTRA_CLIENT_ID || ''),
        'process.env.API_SCOPE': JSON.stringify(process.env.API_SCOPE || ''),
        'process.env.DEV_FAKE_AUTH': JSON.stringify(process.env.DEV_FAKE_AUTH || ''),
      }),
    ],
    devServer: {
      static: { directory: path.join(__dirname, 'dist') },
      // Office requires HTTPS with a TRUSTED cert. Use the office-addin-dev-certs CA the
      // developer installed (`npx office-addin-dev-certs install`) rather than webpack's
      // own untrusted self-signed cert.
      server: httpsOptions
        ? { type: 'https', options: { ca: httpsOptions.ca, cert: httpsOptions.cert, key: httpsOptions.key } }
        : 'https',
      port: DEV_SERVER_PORT,
      headers: { 'Access-Control-Allow-Origin': '*' },
      // Proxy API calls to the (HTTP) Laravel backend so the HTTPS task pane can reach it
      // same-origin — avoids mixed-content blocking and CORS. Requires the backend on :8000.
      proxy: [
        {
          context: ['/api'],
          target: 'http://127.0.0.1:8000',
          secure: false,
          changeOrigin: true,
        },
      ],
    },
  };
};
