import mysql from 'mysql2/promise';
export function createDb(cfg) {
  return mysql.createPool({
    host: cfg.host, port: cfg.port, user: cfg.user, password: cfg.password, database: cfg.database,
    waitForConnections: true, connectionLimit: cfg.connectionLimit, queueLimit: 0,
    charset: 'utf8mb4', supportBigNumbers: true, bigNumberStrings: true,
    decimalNumbers: false, dateStrings: true, timezone: 'Z'
  });
}
