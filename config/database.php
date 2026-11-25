<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'mkm_airleak';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

function db(?string $database = DB_NAME): PDO
{
    $dbName = $database ? 'dbname=' . $database . ';' : '';
    $dsn = sprintf('mysql:host=%s;port=%s;%scharset=%s', DB_HOST, DB_PORT, $dbName, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
