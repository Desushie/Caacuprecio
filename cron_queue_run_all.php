<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

$pdo = db();

$jobKey = 'all';
$jobLabel = 'Ejecutar scraper completo';
$commandPath = __DIR__ . '/py/run_all.py';

try {
    if (!is_file($commandPath)) {
        throw new RuntimeException('No existe el script: ' . $commandPath);
    }

    $lockStmt = $pdo->query("SELECT GET_LOCK('caacuprecio_run_all_cron', 10)");
    $lockAcquired = (int) $lockStmt->fetchColumn() === 1;

    if (!$lockAcquired) {
        echo "No se pudo obtener el lock. Otra ejecución podría estar creando un job.\n";
        exit(0);
    }

    $activeStmt = $pdo->prepare("
        SELECT id, status, created_at, started_at
        FROM scraper_jobs
        WHERE job_key = :job_key
          AND status IN ('pending', 'running')
        ORDER BY id DESC
        LIMIT 1
    ");

    $activeStmt->execute([
        ':job_key' => $jobKey,
    ]);

    $activeJob = $activeStmt->fetch();

    if ($activeJob) {
        echo "Ya existe una actualización completa activa.\n";
        echo "Job #" . $activeJob['id'] . " - Estado: " . $activeJob['status'] . "\n";
        exit(0);
    }

    $insert = $pdo->prepare("
        INSERT INTO scraper_jobs (
            job_key,
            job_label,
            command_path,
            status,
            created_at
        ) VALUES (
            :job_key,
            :job_label,
            :command_path,
            'pending',
            NOW()
        )
    ");

    $insert->execute([
        ':job_key' => $jobKey,
        ':job_label' => $jobLabel,
        ':command_path' => $commandPath,
    ]);

    echo "Job creado correctamente. ID: " . $pdo->lastInsertId() . "\n";
} catch (Throwable $e) {
    error_log('Error creando job automático run_all: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    try {
        $pdo->query("SELECT RELEASE_LOCK('caacuprecio_run_all_cron')");
    } catch (Throwable $e) {
        // Ignorar error al liberar lock.
    }
}