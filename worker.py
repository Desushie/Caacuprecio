import time
import subprocess
import mysql.connector

while True:
    conn = mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="caacuprecio"
    )
    cur = conn.cursor(dictionary=True)

    cur.execute("""
        SELECT * FROM scraper_jobs
        WHERE status = 'pending'
        ORDER BY id ASC
        LIMIT 1
    """)
    job = cur.fetchone()

    if not job:
        conn.close()
        time.sleep(3)
        continue

    cur.execute("""
        UPDATE scraper_jobs
        SET status='running', started_at=NOW()
        WHERE id=%s
    """, (job["id"],))
    conn.commit()

    try:
        result = subprocess.run(
            ["python", job["command_path"]],
            capture_output=True,
            text=True
        )

        status = "done" if result.returncode == 0 else "error"
        output = (result.stdout or "") + "\n" + (result.stderr or "")

        cur.execute("""
            UPDATE scraper_jobs
            SET status=%s, output=%s, finished_at=NOW()
            WHERE id=%s
        """, (status, output, job["id"]))
        conn.commit()

    except Exception as e:
        cur.execute("""
            UPDATE scraper_jobs
            SET status='error', output=%s, finished_at=NOW()
            WHERE id=%s
        """, (str(e), job["id"]))
        conn.commit()

    conn.close()