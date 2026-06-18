#!/bin/bash

# Lint functions for SecureMail CI/CD pipeline
# This file contains reusable functions for various linting operations

# Setup function for lint environment
setup_lint_environment() {
  local linter=$1
  
  echo "╔══════════════════════════════════════════════════════════════╗"
  echo "║             SecureMail $linter Linting Environment           ║"
  echo "╚══════════════════════════════════════════════════════════════╝"
  echo ""
  echo "📋 Lint Information:"
  echo "   • Linter: $linter"
  echo "   • Branch/Tag: $CI_COMMIT_REF_NAME"
  echo ""

  apk add pnpm
}

# Generic function to generate lint report artifacts
generate_lint_report() {
  local linter_name=$1
  local report_file=$2
  local exit_code=$3
  
  if [ "$exit_code" -eq 0 ]; then
    if [ ! -s "$report_file" ]; then
      echo "✅ $linter_name passed."
      echo "No issues found by $linter_name." > "$report_file"
    fi
  else
    echo "❌ $linter_name found issues."
    exit "$exit_code"
  fi
}

# Ansible linting function
lint_ansible() {
  setup_lint_environment "Ansible"
  
  echo "📦 Installing Ansible linting dependencies..."
  apk add --no-cache python3 py3-pip
  pip3 install --break-system-packages ansible-lint
  echo "Ansible-lint version:" && ansible-lint --version
  echo ""
  
  echo "🔍 Finding Ansible files..."
  # Find all Ansible-related YAML files (playbooks, roles, etc.)
  find . -type f \
    \( -name "*.yml" -o -name "*.yaml" \) \
    -path "*/roles/*" -o -path "*/playbooks/*" -o -name "*playbook*" \
    -not -path "*/\.git/*" \
    -not -path "*/\.github/*" \
    -not -name ".gitlab-ci.yml" \
  > ansible_files.txt
  
  echo "Ansible files found:"
  cat ansible_files.txt
  echo ""
  
  # Run ansible-lint on each found file
  if [ -s ansible_files.txt ]; then
    echo "🧹 Running ansible-lint..."
    local lint_failed=0
    while IFS= read -r file; do
      echo "Checking: $file"
      if ! ansible-lint "$file" 2>&1 | tee -a ansible_lint_report.txt; then
        lint_failed=1
      fi
    done < ansible_files.txt
    
    generate_lint_report "ansible-lint" "ansible_lint_report.txt" "$lint_failed"
  else
    echo "ℹ️  No Ansible files found."
    echo "No Ansible files found to lint." > ansible_lint_report.txt
  fi
  
  echo "✅ Ansible linting completed."
}

# Bash/Shell script linting function
lint_bash() {
  setup_lint_environment "Bash/Shell"
  
  echo "📦 Installing Bash linting dependencies..."
  apk add --no-cache shellcheck
  echo "Shellcheck version:" && shellcheck --version
  echo ""
  
  echo "🔍 Finding Bash scripts..."
  # Find all shell script files
  find . -type f \
    \( -name "*.sh" -o -name "*.bash" \) \
    -not -path "*/\.git/*" \
    -not -path "*/\.github/*" \
    -not -name "*.sample" \
    -not -name "*.old" \
  > shell_files.txt
  
  echo "Scripts found:"
  cat shell_files.txt
  echo ""
  
  # Run shellcheck with warning-level severity
  if [ -s shell_files.txt ]; then
    echo "🧹 Running shellcheck..."
    local lint_failed=0
    while IFS= read -r file; do
      echo "Checking: $file"
      if ! shellcheck -S warning "$file" 2>&1 | tee -a bash_lint_report.txt; then
        lint_failed=1
      fi
    done < shell_files.txt
    
    generate_lint_report "shellcheck" "bash_lint_report.txt" "$lint_failed"
  else
    echo "ℹ️  No Bash scripts found."
    echo "No Bash scripts found to lint." > bash_lint_report.txt
  fi
  
  echo "✅ Bash linting completed."
}

# JavaScript linting function
lint_javascript() {
  setup_lint_environment "JavaScript"
  
  echo "📦 Node.js Environment Information..."
  echo "Node.js version:" && node --version
  echo "NPM version:" && npm --version
  echo "PNPM version:" && pnpm --version
  echo ""
  
  echo "🔍 Finding JavaScript files..."
  # Find JS files, excluding build artifacts and dependencies
  find . -type f \
    \( -name "*.js" -o -name "*.mjs" \) \
    -not -path "*/node_modules/*" \
    -not -path "*/\.git/*" \
    -not -path "*/\.github/*" \
    -not -path "*/dist/*" \
    -not -path "*/build/*" \
    -not -name "*.min.js" \
    -not -name "*.bundle.js" \
  > js_files.txt
  
  echo "JavaScript files found:"
  cat js_files.txt
  echo ""
  
  if [ -s js_files.txt ]; then
    echo "🔧 Checking for project dependencies..."
    # Install dependencies if package.json exists
    if [ -f "package.json" ]; then
      echo "package.json found, installing dependencies..."
      pnpm ci || pnpm install
    fi
    
    echo "🔧 Checking for ESLint configuration..."
    # Look for ESLint configuration files
    if [ -f "eslint.config.js" ] || [ -f ".eslintrc.js" ] || \
       [ -f ".eslintrc.json" ] || [ -f ".eslintrc.yml" ] || \
       [ -f ".eslintrc.yaml" ]; then
      echo "ESLint config found, running ESLint..."
      
      # Use local ESLint if available, otherwise try global installation
      ESLINT_CMD="eslint"
      if [ -f "node_modules/.bin/eslint" ]; then
        ESLINT_CMD="./node_modules/.bin/eslint"
      elif ! command -v eslint >/dev/null 2>&1; then
        echo "Installing ESLint globally..."
        pnpm install -g eslint
      fi
      
      echo "🧹 Running ESLint..."
      local lint_failed=0
      if ! xargs -a js_files.txt "$ESLINT_CMD" 2>&1 | tee js_lint_report.txt; then
        lint_failed=1
      fi
      
      generate_lint_report "ESLint" "js_lint_report.txt" "$lint_failed"
    else
      echo "ℹ️  No ESLint config found, running basic syntax check..."
      
      echo "🧹 Running basic JavaScript syntax check..."
      local syntax_failed=0
      while IFS= read -r file; do
        echo "Checking syntax: $file"
        if ! node -c "$file" 2>&1 | tee -a js_lint_report.txt; then
          syntax_failed=1
        fi
      done < js_files.txt
      
      generate_lint_report "JavaScript syntax check" "js_lint_report.txt" "$syntax_failed"
    fi
  else
    echo "ℹ️  No JavaScript files found."
    echo "No JavaScript files found to lint." > js_lint_report.txt
  fi
  
  echo "✅ JavaScript linting completed."
}

# YAML linting function
lint_yaml() {
  setup_lint_environment "YAML"
  
  echo "📦 Installing YAML linting dependencies..."
  apk add --no-cache python3 py3-pip
  pip3 install --break-system-packages yamllint
  echo "yamllint version:" && yamllint --version
  echo ""
  
  echo "🔍 Finding YAML files..."
  # Find all YAML files, excluding samples and dependencies
  find . -type f \
    \( -name "*.yml" -o -name "*.yaml" \) \
    -not -path "*/\.git/*" \
    -not -path "*/\.github/*" \
    -not -path "*/node_modules/*" \
    -not -name "*.sample" \
    -not -name "*.old" \
  > yaml_files.txt
  
  echo "YAML files found:"
  cat yaml_files.txt
  echo ""
  
  # Run yamllint on each file
  if [ -s yaml_files.txt ]; then
    echo "🧹 Running yamllint..."
    local lint_failed=0
    while IFS= read -r file; do
      echo "Checking: $file"
      if ! yamllint "$file" 2>&1 | tee -a yaml_lint_report.txt; then
        lint_failed=1
      fi
    done < yaml_files.txt
    
    generate_lint_report "yamllint" "yaml_lint_report.txt" "$lint_failed"
  else
    echo "ℹ️  No YAML files found."
    echo "No YAML files found to lint." > yaml_lint_report.txt
  fi
  
  echo "✅ YAML linting completed."
} 