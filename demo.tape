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

# Intro
Type "# 🐝 Hive — Laravel agent orchestration CLI"
Enter
Sleep 800ms

# hive init
Type "cd ~/Herd/malistedenvies"
Enter
Sleep 500ms

Type "hive init"
Enter
Sleep 2s

# hive plan --text (pas besoin de GitHub token pour la démo)
Type "hive plan --text 'Fix SQL injection CVE in composer deps. Fix npm audit vulnerabilities. Update Laravel to 12.x. Upgrade Filament to v5. Add dark mode to admin panel.' --dry-run"
Enter
Sleep 4s

# hive fix --nightwatch dry-run
Type "hive fix --nightwatch --limit 3 --dry-run"
Enter
Sleep 3s

# hive status
Type "hive status"
Enter
Sleep 1s

Sleep 2s
