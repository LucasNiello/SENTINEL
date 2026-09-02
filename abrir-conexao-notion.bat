@echo off
REM Checklist de setup do ambiente (VS Code + Claude + Notion + Brave) numa maquina nova.
REM Nao guarda nem pede nenhum token/senha - so abre as paginas certas e lista os passos manuais.

echo ============================================
echo  SETUP DE MAQUINA NOVA - SENTINEL
echo ============================================
echo.

echo [1/4] VS CODE - Settings Sync
echo   Traz extensoes, settings.json, keybindings e a config da extensao Claude Code.
echo   Passos:
echo    - Abra o VS Code
echo    - Ctrl+Shift+P - "Settings Sync: Turn On"
echo    - Login com a MESMA conta (GitHub ou Microsoft) usada nas outras maquinas
echo   (Se ja estiver ligado, o comando aparece como "Turn Off" - nao precisa fazer nada)
echo.
pause

echo [2/4] CLAUDE (extensao Claude Code)
echo   Login com a mesma conta Claude. Os Connectors (Notion, Figma, Canva, etc.)
echo   ficam presos a conta, entao ja aparecem conectados automaticamente - nao
echo   precisa recriar integracao nem reconectar a pagina no Notion.
echo   Obs: na primeira conexao pode dar timeout/demorar - so espere e tente de novo.
echo.
pause

echo [3/4] NOTION
echo   Nada a configurar aqui - e so nuvem. Login com sua conta (app ou navegador).
start "" "https://www.notion.so/login"
echo.
pause

echo [4/4] BRAVE - Brave Sync
echo   Traz favoritos, extensoes, senhas e historico do navegador.
echo   Passos:
echo    - Menu do Brave (icone com 3 linhas) - Sync
echo    - "Ja tenho uma frase de sync" - cole o codigo/frase gerado na maquina original
echo   (Se esta e a maquina original e ainda nao tem frase de sync, va em
echo    Sync - Iniciar sincronizacao - Este e o primeiro dispositivo, e guarde a frase)
start "" "brave://sync-setup"
echo.

echo ============================================
echo  Pronto. Confirme no VS Code que as extensoes
echo  baixaram (Ctrl+Shift+X) antes de comecar a trabalhar.
echo ============================================
pause
