@echo off
REM Starts the GASA API dev server on the port the merchant-platform frontend
REM expects (8010), using PHP 8.4. Plain "php" on this machine resolves to
REM XAMPP's 8.2, which the installed vendor packages refuse to run on.
set PHP84=C:\Users\Luigie\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe
"%PHP84%" artisan serve --host=127.0.0.1 --port=8010 %*
