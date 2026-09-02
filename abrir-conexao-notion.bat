@echo off
REM Atalho para configurar a integracao Notion + Claude em uma maquina nova.
REM Nao guarda nenhum token - abre as duas paginas onde a autenticacao e feita manualmente.

echo Passo 1: crie/gerencie a integracao no Notion (se precisar de token de acesso)
start "" "https://www.notion.so/profile/integrations"

echo Passo 2: conecte o Notion aos Connectors da sua conta Claude (login OAuth)
start "" "https://claude.ai/directory/notion"

echo.
echo Depois de conectar:
echo  1. Na pagina "SENTINEL" no Notion, va em "..." (canto superior direito) - Connections - Connect to - selecione a conexao criada.
echo  2. Recarregue/reinicie o Claude Code (Ctrl+Shift+P - Developer: Reload Window) para a extensao reconectar.
echo.
pause
