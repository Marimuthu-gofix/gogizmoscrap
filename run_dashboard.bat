@echo off
cd /d "%~dp0"
echo Starting the buyback price dashboard at http://127.0.0.1:5050
if exist ".venv\Scripts\python.exe" (
    ".venv\Scripts\python.exe" dashboard.py
) else (
    python dashboard.py
)
pause
