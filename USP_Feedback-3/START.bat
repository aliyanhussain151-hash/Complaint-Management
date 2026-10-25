@echo off
setlocal enableextensions
title USP Complaint System - Setup and Run
cd /d "%~dp0"

echo ============================================================
echo    USP Complaint ^& Feedback Management System
echo ============================================================
echo.

set "XAMPP=C:\xampp"
set "PHP=%XAMPP%\php\php.exe"
set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"
set "MYSQLD=%XAMPP%\mysql\bin\mysqld.exe"

if not exist "%PHP%" (
  echo   XAMPP was not found at %XAMPP%.
  echo.
  echo   1^) Download and install XAMPP from:
  echo        https://www.apachefriends.org/download.html
  echo   2^) Install it to the DEFAULT folder ^(C:\xampp^).
  echo   3^) Double-click this START.bat again.
  echo.
  pause
  exit /b 1
)

echo [1/3] Starting MySQL ...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
if errorlevel 1 (
  start "MySQL" /min "%MYSQLD%" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
  echo       Waiting for MySQL to be ready ...
  timeout /t 8 /nobreak >NUL
) else (
  echo       MySQL is already running.
)

echo [2/3] Checking the database ...
rem Only load the database when the `usp` database does not exist yet.
rem Unconditionally re-importing usp_custom.sql here used to DROP and reset
rem the whole database on every launch, destroying all data and schema
rem changes. Import is now first-run only; re-run migrations via
rem php tools\migrate.php if needed.
"%MYSQL%" -u root -N -e "SHOW DATABASES LIKE 'usp'" | find /I "usp" >NUL
if errorlevel 1 (
  echo       First run detected - loading database\usp_custom.sql ...
  "%MYSQL%" -u root < "database\usp_custom.sql"
  if errorlevel 1 (
    echo.
    echo       WARNING: could not load the database automatically.
    echo       If your MySQL root user has a password, either remove it,
    echo       or import database\usp_custom.sql yourself in phpMyAdmin.
    echo.
  ) else (
    echo       Database loaded. Applying migrations ...
    "%PHP%" "tools\migrate.php"
  )
) else (
  echo       Database already exists - left untouched.
)

echo [3/3] Starting the website ...
start "USP Server - close this window to stop" /D "%~dp0" "%PHP%" -S localhost:8000
timeout /t 2 /nobreak >NUL
start "" "http://localhost:8000"

echo.
echo   DONE.  Your browser should open at:  http://localhost:8000
echo.
echo   Logins:
echo      Admin :  admin@usp.com   /  admin123
echo      Staff :  staff@usp.com   /  staff123
echo      User  :  user@usp.com    /  user1234
echo.
echo   To STOP the website: close the "USP Server" window.
echo.
pause
