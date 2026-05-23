<?php
// db_config.php - Database configuration for online portal
function getDBConnection()
{
    $host = 'localhost'; // Your database host
    $dbname = 'impactdi_school_portal'; // Your database name
    $username = 'impactdi_school_portal'; // Your database username
    $password = 'Innioluwa@1995'; // Your database password

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}
