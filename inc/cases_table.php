<?php
declare(strict_types=1);

function channelToDefaultTable(string $channel): string {
  $map = ['email'=>'training_mail_cases','sms'=>'training_sms_cases','web'=>'training_web_cases'];
  if (!isset($map[$channel])) throw new RuntimeException('Invalid channel');
  return $map[$channel];
}

function getModuleRow(PDO $pdo, int $moduleId): array {
  $stmt = $pdo->prepare("SELECT id, title, channel, cases_table, difficulty, is_active, description
                         FROM training_modules WHERE id=:id");
  $stmt->execute([':id'=>$moduleId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Module not found');
  return $row;
}


// Returns the correct cases table for a module.
// If module uses a dedicated table, return that; otherwise pick by channel.
function getCasesTableForModule(PDO $pdo, int $moduleId, ?string $channel = null): string {
  $st = $pdo->prepare("SELECT channel, use_dedicated_cases, cases_table FROM training_modules WHERE id=? LIMIT 1");
  $st->execute([$moduleId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $ch = strtolower($channel ?: ($row['channel'] ?? 'email'));

  if (!empty($row['use_dedicated_cases']) && !empty($row['cases_table'])) {
    // safety: allow only [A-Za-z0-9_]
    return preg_replace('/[^a-zA-Z0-9_]/', '', $row['cases_table']);
  }

  // default pools by channel (treat "mobile" as SMS)
  switch ($ch) {
    case 'sms':
    case 'mobile':
      return 'training_sms_cases';
    case 'web':
    case 'website':
      return 'training_web_cases';
    default:
      return 'training_mail_cases';
  }
}

function ensureCasesTable(PDO $pdo, int $moduleId): string {
  $table = "training_cases_m{$moduleId}";
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `$table` (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      module_id INT UNSIGNED NOT NULL,
      title VARCHAR(255) NOT NULL,
      fake_url VARCHAR(255) NULL,
      brand_hint VARCHAR(255) NULL,
      screenshot_path VARCHAR(255) NULL,
      points_max INT NOT NULL DEFAULT 10,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      case_config_json TEXT NULL,
      body TEXT NULL,
      image_url VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_module (module_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $stmt = $pdo->prepare("UPDATE training_modules SET cases_table=:t WHERE id=:id");
  $stmt->execute([':t'=>$table, ':id'=>$moduleId]);
  return $table;
}
