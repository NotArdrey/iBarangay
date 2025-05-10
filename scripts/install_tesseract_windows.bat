@echo off
echo ============================================================
echo Tesseract OCR Setup Helper for Windows
echo ============================================================
echo.
echo This script will help you set up Tesseract OCR for ID extraction.
echo.

REM Check if Tesseract is already installed
where tesseract >nul 2>nul
if %ERRORLEVEL% == 0 (
    echo Tesseract is already installed and in your PATH.
    tesseract --version
    echo.
    goto check_python
)

echo Tesseract OCR not found in PATH. Checking common installation locations...

set FOUND=0

REM Check common installation paths
for %%P in (
    "C:\Program Files\Tesseract-OCR\tesseract.exe"
    "C:\Program Files (x86)\Tesseract-OCR\tesseract.exe"
    "C:\Tesseract-OCR\tesseract.exe"
) do (
    if exist %%P (
        echo Found Tesseract at: %%P
        set TESSERACT_PATH=%%P
        set FOUND=1
        goto add_to_path
    )
)

if %FOUND% == 0 (
    echo Tesseract not found. You need to install it.
    echo.
    echo Please download Tesseract from:
    echo https://github.com/UB-Mannheim/tesseract/wiki
    echo.
    echo During installation:
    echo 1. Check "Add to PATH" option
    echo 2. Install to a path without spaces (e.g., C:\Tesseract-OCR)
    echo.
    echo After installation, run this script again.
    pause
    start "" "https://github.com/UB-Mannheim/tesseract/wiki"
    exit /b 1
)

:add_to_path
echo.
echo Do you want to add Tesseract to your PATH? (Y/N)
set /p CHOICE="> "

if /i "%CHOICE%" == "Y" (
    echo Adding Tesseract to PATH...
    
    REM Extract the directory from the full path
    for %%i in ("%TESSERACT_PATH%") do set TESSERACT_DIR=%%~dpi
    
    REM Remove trailing backslash if present
    if %TESSERACT_DIR:~-1%==\ set TESSERACT_DIR=%TESSERACT_DIR:~0,-1%
    
    REM Add to PATH (for current session)
    set PATH=%PATH%;%TESSERACT_DIR%
    
    REM Add to PATH permanently
    setx PATH "%PATH%;%TESSERACT_DIR%" /M
    
    echo Tesseract added to PATH.
) else (
    echo Skipping PATH setup. 
    echo Note: You'll need to manually add Tesseract to your PATH, or edit the Python script with the full path.
)

:check_python
echo.
echo Checking Python installation...
where python >nul 2>nul
if %ERRORLEVEL% == 0 (
    echo Python is installed.
    python --version
) else (
    echo Python not found in PATH.
    echo Please install Python from https://www.python.org/downloads/
    echo Make sure to check "Add Python to PATH" during installation.
    pause
    start "" "https://www.python.org/downloads/"
    exit /b 1
)

:check_packages
echo.
echo Checking required Python packages...
echo.

REM Create a temporary script to check packages
echo import importlib.util > check_packages.py
echo packages = ['pytesseract', 'opencv-python', 'numpy', 'Pillow'] >> check_packages.py
echo missing = [] >> check_packages.py
echo for package in packages: >> check_packages.py
echo     if importlib.util.find_spec(package.split('-')[0]) is None: >> check_packages.py
echo         missing.append(package) >> check_packages.py
echo print(",".join(missing)) >> check_packages.py

REM Run the script to check packages
for /f %%i in ('python check_packages.py') do set MISSING_PACKAGES=%%i

REM Clean up
del check_packages.py

if not "%MISSING_PACKAGES%" == "" (
    echo Missing Python packages: %MISSING_PACKAGES%
    echo.
    echo Do you want to install these packages? (Y/N)
    set /p INSTALL_CHOICE="> "
    
    if /i "%INSTALL_CHOICE%" == "Y" (
        echo Installing packages...
        pip install %MISSING_PACKAGES:.=% -r ..\requirements.txt
    ) else (
        echo Skipping package installation.
        echo You will need to manually install: %MISSING_PACKAGES%
    )
) else (
    echo All required Python packages are installed.
)

:check_temp_dir
echo.
echo Checking if temp_uploads directory exists...
if not exist "..\temp_uploads" (
    echo Creating temp_uploads directory...
    mkdir "..\temp_uploads"
    echo Directory created.
) else (
    echo temp_uploads directory exists.
)

echo.
echo ============================================================
echo Setup completed!
echo.
echo If you encounter any issues, please refer to README_OCR.md
echo for additional troubleshooting steps.
echo ============================================================

pause 