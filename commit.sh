#!/bin/bash

# Check if a commit message is provided
if [ -z "$1" ]; then
    echo "Usage: $0 \"commit message\""
    exit 1
fi

# Define fancy symbols (You can customize these)
SYMBOLS=("🚀" "✨" "🔥" "🔧" "🛠️" "📦" "✅" "💡" "🐛" "🔄" "🎉" "📌" "📈" "🔒" "🔑" "🌟" "🎯" "🚨" "💬" "📝" "🛑" "📅" "📂" "🗂️" "📂" "🔎" "🔔" "⚡" "🧪" "🔗" "📤" "📥" "⏱️" "⚙️" "🖥️" "🔍" "🌍" "🌐" "🛠️" "⚡" "📋" "��" "⏳" "⏰" "🔮" "🧰" "💬" "��" "💻" "🎥" "💥" "⛑️" "🔧" "🔨" "🛠️" "🛡️" "⚒️" "🔩" "📦" "🏷️" "🖌️" "🎮" "📃" "💻" "🗣️" "📝" "��" "🗓️" "📋" "🔓" "🔐" "" "⚙️" "🗜️" "🛠️" "💡" "🔌" "🔗" "🖱️" "📊" "��" "📃" "📎" "🔊" "🖥️" "��‍💻" "👩‍💻" "💽" "📡" "⚙️" "🎮" "🎥" "🖋️" "🔗" "🗑️" "💡" "🎯" "⏳" "🌐" "🔧")


# Pick a random symbol
RANDOM_SYMBOL=${SYMBOLS[$RANDOM % ${#SYMBOLS[@]}]}

# Add all changes
git add .

# Commit with the provided message + a fancy symbol
git commit -m "$RANDOM_SYMBOL $1"

# Push to the current branch
git push

echo "Changes committed with style! $RANDOM_SYMBOL"

