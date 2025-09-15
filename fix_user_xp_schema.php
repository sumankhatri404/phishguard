<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';

try { $pdo->exec("ALTER TABLE user_xp ADD COLUMN module_id INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE user_xp MODIFY module_id INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE user_xp DROP PRIMARY KEY"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE user_xp ADD PRIMARY KEY (user_id, module_id)"); } catch (Throwable $e) {}
echo "OK";
