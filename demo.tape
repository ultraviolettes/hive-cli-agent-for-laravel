# Hive CLI — Demo
# Run with: vhs demo.tape

Output demo.gif

Require echo

Set Shell "zsh"
Set FontSize 14
Set FontFamily "JetBrains Mono"
Set Width 1000
Set Height 600
Set Theme "Dracula"
Set Padding 20
Set TypingSpeed 60ms

# Se placer directement dans le projet Laravel
Type "cd ~/Herd/malistedenvies"
Enter
Sleep 500ms

# hive init
Type "php ~/Projects/HIVE/repositories/hive-cli-laravel-agents/hive init"
Enter
Sleep 2500ms

# hive plan --text dry-run — le money shot
Type "php ~/Projects/HIVE/repositories/hive-cli-laravel-agents/hive plan --text 'Fix SQL injection CVE in composer deps. Fix npm audit vulnerabilities. Update Laravel to 12.x. Upgrade Filament to v5. Add dark mode to admin panel.' --dry-run"
Enter
Sleep 15s

# hive status
Type "php ~/Projects/HIVE/repositories/hive-cli-laravel-agents/hive status"
Enter
Sleep 2s

Sleep 3s
