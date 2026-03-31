import time
import subprocess
import os
import tempfile
import mysql.connector


DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "caacuprecio",
}

POLL_SECONDS = 3
CHECK_CANCEL_SECONDS = 1
PROCESS_TIMEOUT_SECONDS = None  # Ej: 3600 para 1 hora


def get_connection():
    return mysql.connector.connect(**DB_CONFIG)


def fetch_next_pending_job():
    conn = get_connection()
    cur = conn.cursor(dictionary=True)

    cur.execute("""
        SELECT *
        FROM scraper_jobs
        WHERE status = 'pending'
        ORDER BY id ASC
        LIMIT 1
    """)
    job = cur.fetchone()

    cur.close()
    conn.close()
    return job


def mark_job_running(job_id):
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        UPDATE scraper_jobs
        SET status = 'running',
            started_at = NOW(),
            finished_at = NULL,
            output = NULL
        WHERE id = %s
          AND status = 'pending'
    """, (job_id,))
    conn.commit()

    affected = cur.rowcount

    cur.close()
    conn.close()
    return affected > 0


def update_job_pid(job_id, pid):
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        UPDATE scraper_jobs
        SET pid = %s
        WHERE id = %s
    """, (pid, job_id))
    conn.commit()

    cur.close()
    conn.close()


def get_job_status(job_id):
    conn = get_connection()
    cur = conn.cursor(dictionary=True)

    cur.execute("""
        SELECT status
        FROM scraper_jobs
        WHERE id = %s
        LIMIT 1
    """, (job_id,))
    row = cur.fetchone()

    cur.close()
    conn.close()

    return row["status"] if row else None


def finish_job(job_id, status, output):
    conn = get_connection()
    cur = conn.cursor()

    cur.execute("""
        UPDATE scraper_jobs
        SET status = %s,
            output = %s,
            finished_at = NOW(),
            pid = NULL
        WHERE id = %s
    """, (status, output, job_id))
    conn.commit()

    cur.close()
    conn.close()


def is_process_alive(process):
    return process.poll() is None


def kill_process(process):
    if not is_process_alive(process):
        return

    try:
        process.kill()
    except Exception:
        pass


def read_file_safely(path):
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as f:
            return f.read().strip()
    except Exception as e:
        return f"[Worker] No se pudo leer log temporal: {e}"


def run_job(job):
    process = None
    temp_log_path = None

    try:
        with tempfile.NamedTemporaryFile(delete=False, suffix=".log", mode="w", encoding="utf-8") as tmp:
            temp_log_path = tmp.name

        log_file = open(temp_log_path, "w", encoding="utf-8", errors="replace")

        process = subprocess.Popen(
            ["python", job["command_path"]],
            stdout=log_file,
            stderr=subprocess.STDOUT,
            text=True,
            cwd=os.path.dirname(job["command_path"])
        )

        update_job_pid(job["id"], process.pid)
        start_time = time.time()

        while True:
            if not is_process_alive(process):
                break

            current_status = get_job_status(job["id"])
            if current_status == "cancelled":
                kill_process(process)
                process.wait(timeout=5)
                log_file.close()
                output = read_file_safely(temp_log_path)
                finish_job(job["id"], "cancelled", output)
                return

            if (
                PROCESS_TIMEOUT_SECONDS is not None
                and (time.time() - start_time) > PROCESS_TIMEOUT_SECONDS
            ):
                kill_process(process)
                process.wait(timeout=5)
                log_file.close()
                output = read_file_safely(temp_log_path)
                output = (output + "\n\n[Worker] Proceso finalizado por timeout.").strip()
                finish_job(job["id"], "error", output)
                return

            time.sleep(CHECK_CANCEL_SECONDS)

        return_code = process.wait()
        log_file.close()
        output = read_file_safely(temp_log_path)

        final_status_in_db = get_job_status(job["id"])
        if final_status_in_db == "cancelled":
            finish_job(job["id"], "cancelled", output)
            return

        status = "done" if return_code == 0 else "error"
        finish_job(job["id"], status, output)

    except Exception as e:
        if process is not None:
            kill_process(process)
        finish_job(job["id"], "error", str(e))

    finally:
        if temp_log_path and os.path.exists(temp_log_path):
            try:
                os.remove(temp_log_path)
            except Exception:
                pass


def main():
    while True:
        job = fetch_next_pending_job()

        if not job:
            time.sleep(POLL_SECONDS)
            continue

        locked = mark_job_running(job["id"])
        if not locked:
            time.sleep(1)
            continue

        run_job(job)


if __name__ == "__main__":
    main()