const fs = require("fs");
const path = require("path");

function loadEnvFile(filePath) {
  const env = {};
  if (!fs.existsSync(filePath)) {
    return env;
  }

  const lines = fs.readFileSync(filePath, "utf8").split(/\r?\n/);
  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line || line.startsWith("#")) continue;

    const normalized = line.startsWith("export ") ? line.slice(7).trim() : line;
    const index = normalized.indexOf("=");
    if (index === -1) continue;

    const key = normalized.slice(0, index).trim();
    let value = normalized.slice(index + 1).trim();
    if (!key) continue;

    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    env[key] = value;
  }

  return env;
}

const projectEnv = loadEnvFile(path.resolve(__dirname, "../.env"));

module.exports = {
  apps: [
    {
      name: "battle-ws-test",
      script: "ws-battle.js",
      cwd: __dirname,
      instances: 1,
      autorestart: true,
      exec_mode: "fork",
      watch: false,
      env: {
        ...projectEnv,
        NODE_ENV: "test",
        QB_WS_HOST: projectEnv.QB_WS_HOST || "0.0.0.0",
        QB_WS_PORT: projectEnv.QB_WS_PORT || "8085",
        QB_WS_SYNC_BASE_URL: projectEnv.QB_WS_SYNC_BASE_URL || projectEnv.BASE_URL || "https://battle-test.quizbattle.jp/"
      }
    }
  ]
};
