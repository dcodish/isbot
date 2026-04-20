"""
One-time script: exports all questions to runtime/questions_export.txt

Usage:
    python tools/export_questions.py --host HOST --user USER --pass PASSWORD --db DBNAME
"""
import argparse
import os
import pymysql

parser = argparse.ArgumentParser()
parser.add_argument('--host', required=True)
parser.add_argument('--user', required=True)
parser.add_argument('--pass', dest='password', required=True)
parser.add_argument('--db',   required=True)
args = parser.parse_args()

conn = pymysql.connect(
    host=args.host, user=args.user, password=args.password,
    database=args.db, charset='utf8mb4'
)

with conn.cursor() as cur:
    cur.execute("SELECT id, question_text, option1, option2, option3, option4 FROM questions ORDER BY id")
    rows = cur.fetchall()

conn.close()

out_dir = os.path.join(os.path.dirname(__file__), '..', 'runtime')
os.makedirs(out_dir, exist_ok=True)
out_path = os.path.join(out_dir, 'questions_export.txt')

with open(out_path, 'w', encoding='utf-8') as f:
    for r in rows:
        f.write(' | '.join(str(x) for x in r) + '\n')

print(f"Exported {len(rows)} questions to runtime/questions_export.txt")
