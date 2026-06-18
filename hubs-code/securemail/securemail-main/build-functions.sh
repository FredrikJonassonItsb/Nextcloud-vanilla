#!/bin/bash

# Setup function for build environment
setup_build_environment() {
  local component=$1
  
  echo "╔══════════════════════════════════════════════════════════════╗"
  echo "║             SecureMail $component Build Environment          ║"
  echo "╚══════════════════════════════════════════════════════════════╝"
  echo ""
  echo "📋 Build Information:"
  echo "   • Branch/Tag: $CI_COMMIT_REF_NAME"
  echo "   • Registry: $REGISTRY_URL"
  echo "   • Project: $PROJECT_PATH"
  echo "   • Component: $component"
  echo ""
  
  echo "📦 Installing dependencies..."
  echo "   • Installing git for semantic version comparison"
  apk add --no-cache git >/dev/null 2>&1
  echo "   ✅ Git installed: $(git --version)"
  
  echo ""
  echo "🏷️  Fetching repository tags..."
  if git fetch origin '+refs/tags/*:refs/tags/*' >/dev/null 2>&1 || git fetch --tags --force >/dev/null 2>&1; then
    echo "   ✅ Tags fetched successfully"
    echo "   • Total tags: $(git tag -l | wc -l)"
    echo "   • Latest tags: $(git tag -l --sort=-version:refname | head -5 | tr '\n' ' ')"
  else
    echo "   ⚠️  Warning: Could not fetch all tags"
  fi
  
  echo ""
  echo "🐋 Docker Daemon Status Check"
  echo "   • Host: ${DOCKER_HOST:-unix:///var/run/docker.sock}"
  
  # Dynamic Docker daemon check with detailed status
  local attempts=0
  local max_attempts=10
  
  while [ $attempts -lt $max_attempts ]; do
    if docker version >/dev/null 2>&1; then
      echo "   ✅ Docker daemon is ready!"
      echo ""
      echo "   Docker Version Information:"
      echo "   Client: $(docker version --format '{{.Client.Version}}')"
      echo "   Server: $(docker version --format '{{.Server.Version}}')"
      break
    else
      attempts=$((attempts + 1))
      echo "   ⏳ Attempt $attempts/$max_attempts - Waiting for Docker daemon..."
      
      # Provide more detailed status at certain intervals
      if [ $attempts -eq 3 ]; then
        echo "   ℹ️  Docker daemon is taking longer than usual to start..."
             elif [ $attempts -eq 6 ]; then
         echo "   ⚠️  Still waiting... checking Docker service status"
         # Try to get some diagnostic info
         pgrep -f docker | head -3 2>/dev/null || true
       fi
      
      sleep 3
    fi
  done
  
  if [ $attempts -eq $max_attempts ]; then
    echo "   ❌ ERROR: Docker daemon failed to start after $max_attempts attempts"
    echo "   Last error output:"
    docker version 2>&1 | head -10
    exit 1
  fi
  
  echo ""
  echo "🧹 Docker Cache Management"
  echo "   • Pruning unused build cache..."
  if docker builder prune -af >/dev/null 2>&1; then
    echo "   ✅ Build cache cleaned"
  else
    echo "   ⚠️  Build cache cleanup failed (non-critical)"
  fi
  
  echo "   • Removing dangling images..."
  if docker image prune -f >/dev/null 2>&1; then
    echo "   ✅ Dangling images removed"
  else
    echo "   ⚠️  Image cleanup failed (non-critical)"
  fi
  
  echo ""
  echo "🔐 Container Registry Authentication"
  echo "   • Registry: $REGISTRY_URL"
  echo "   • User: $CI_REGISTRY_USER"
  
  if echo "$CI_REGISTRY_PASSWORD" | docker login -u "$CI_REGISTRY_USER" --password-stdin "$REGISTRY_URL" >/dev/null 2>&1; then
    echo "   ✅ Successfully authenticated with container registry"
  else
    echo "   ❌ ERROR: Failed to authenticate with container registry"
    exit 1
  fi
  
  echo ""
  echo "═══════════════════════════════════════════════════════════════"
  echo "✅ Build environment setup complete!"
  echo "═══════════════════════════════════════════════════════════════"
  echo ""
}

# Semantic versioning comparison functions
compare_semver() {
  local new_ver="$1"
  local latest_ver="$2"

  # Input validation
  if [ -z "$new_ver" ]; then
    echo "ERROR: compare_semver: new_ver is empty" >&2
    return 1
  fi

  # Strip 'v' prefix if present
  new_ver=${new_ver#v}
  latest_ver=${latest_ver#v}

  # If no latest version exists, new version should be latest
  if [ -z "$latest_ver" ] || [ "$latest_ver" = "none" ]; then
    return 0  # true - should update
  fi

  # Validate semver format (basic check)
  if ! echo "$new_ver" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "ERROR: Invalid semver format for new version: $new_ver" >&2
    return 1
  fi
  if ! echo "$latest_ver" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "ERROR: Invalid semver format for latest version: $latest_ver" >&2
    return 1
  fi

  # Split versions into major.minor.patch with validation
  new_major=$(echo "$new_ver" | cut -d'.' -f1)
  new_minor=$(echo "$new_ver" | cut -d'.' -f2)
  new_patch=$(echo "$new_ver" | cut -d'.' -f3)
  latest_major=$(echo "$latest_ver" | cut -d'.' -f1)
  latest_minor=$(echo "$latest_ver" | cut -d'.' -f2)
  latest_patch=$(echo "$latest_ver" | cut -d'.' -f3)

  # Validate all components are numeric
  for component in "$new_major" "$new_minor" "$new_patch" "$latest_major" "$latest_minor" "$latest_patch"; do
    if ! echo "$component" | grep -qE '^[0-9]+$'; then
      echo "ERROR: Non-numeric version component: $component" >&2
      return 1
    fi
  done

  # Compare major version
  if [ "$new_major" -gt "$latest_major" ]; then
    return 0  # true - new major version
  elif [ "$new_major" -lt "$latest_major" ]; then
    return 1  # false - older major version
  fi

  # Major versions equal, compare minor
  if [ "$new_minor" -gt "$latest_minor" ]; then
    return 0  # true - new minor version
  elif [ "$new_minor" -lt "$latest_minor" ]; then
    return 1  # false - older minor version
  fi

  # Major and minor equal, compare patch
  if [ "$new_patch" -gt "$latest_patch" ]; then
    return 0  # true - new patch version
  else
    return 1  # false - older or equal patch version
  fi
}

get_latest_version_tag() {
  # Get the latest semantic version tag from git, excluding the current tag
  # This finds the highest version tag, not just the most recent chronologically
  local current_tag="$1"
  local git_output
  local filtered_tags

  # Input validation
  if [ -z "$current_tag" ]; then
    echo "ERROR: get_latest_version_tag: current_tag is empty" >&2
    echo "none"
    return 1
  fi

  # Get git tags with error handling
  if ! git_output=$(git tag -l 'v*' 2>/dev/null); then
    echo "ERROR: Failed to fetch git tags" >&2
    echo "none"
    return 1
  fi

  # Filter to valid semver tags, excluding current tag
  filtered_tags=$(echo "$git_output" | \
    grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | \
    grep -v "^${current_tag}$" | \
    sort -V 2>/dev/null || true)

  if [ -z "$filtered_tags" ]; then
    echo "none"
    return 0
  fi

  # Get the highest version
  echo "$filtered_tags" | tail -1 || echo "none"
}

# Build functions for SecureMail CI/CD pipeline
build_main_branch() {
  echo "=== Checking Main Branch Condition ==="
  echo "Current branch: '$CI_COMMIT_REF_NAME'"
  echo "Checking if branch equals 'main'..."

  if [ "$CI_COMMIT_REF_NAME" = "main" ]; then
    echo "✅ MATCH: Building $1 for main branch"
    # shellcheck disable=SC2086  # We want word splitting for build args
    timeout "$DOCKER_BUILD_TIMEOUT" docker build $2 \
      --network=host --progress=plain \
      -t "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:main" "./$1"
    timeout "$REGISTRY_TIMEOUT" docker push "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:main"
    echo "✅ $1 main build & push to CR completed"
  else
    echo "❌ SKIP: Branch '$CI_COMMIT_REF_NAME' != 'main'"
  fi
}

# Build develop branch for integration testing
build_develop_branch() {
  echo "=== Checking Develop Branch Condition ==="
  echo "Current branch: '$CI_COMMIT_REF_NAME'"
  echo "Checking if branch equals 'develop'..."

  if [ "$CI_COMMIT_REF_NAME" = "develop" ]; then
    echo "✅ MATCH: Building $1 for develop branch"
    # shellcheck disable=SC2086  # We want word splitting for build args
    timeout "$DOCKER_BUILD_TIMEOUT" docker build $2 \
      --network=host --progress=plain \
      -t "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:develop" "./$1"
    timeout "$REGISTRY_TIMEOUT" docker push "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:develop"
    echo "✅ $1 develop build & push to CR completed"
  else
    echo "❌ SKIP: Branch '$CI_COMMIT_REF_NAME' != 'develop'"
  fi
}

# Build feature branches for development/testing
# Pattern: branches starting with ticket number (e.g., 43-align-cicd-branching-strategy)
build_feature_branch() {
  echo "=== Checking Feature Branch Condition ==="
  echo "Current branch: '$CI_COMMIT_REF_NAME'"

  # Safety check: Validate branch name is not empty
  if [ -z "$CI_COMMIT_REF_NAME" ]; then
    echo "❌ ERROR: Empty branch name detected"
    exit 1
  fi

  # Skip if it's main, develop, a tag, or the test branch
  if [ "$CI_COMMIT_REF_NAME" = "main" ] || \
     [ "$CI_COMMIT_REF_NAME" = "develop" ] || \
     [ -n "$CI_COMMIT_TAG" ] || \
     [ "$CI_COMMIT_REF_NAME" = "ci-pipeline-test-temp" ]; then
    echo "❌ SKIP: Not a feature branch (main/develop/tag/test branch)"
    return
  fi

  # Check if branch starts with ticket number (e.g., 43-feature-name)
  if ! echo "$CI_COMMIT_REF_NAME" | grep -qE '^[0-9]+-'; then
    echo "❌ SKIP: Branch '$CI_COMMIT_REF_NAME' does not match ticket# pattern (e.g., 43-feature-name)"
    echo "   Only branches starting with ticket number are built as feature branches"
    return
  fi

  echo "✅ MATCH: Building feature branch '$CI_COMMIT_REF_NAME'"
  
  # Create safe branch name for Docker tag (replace invalid characters)
  local safe_branch_name
  safe_branch_name=$(echo "$CI_COMMIT_REF_NAME" | sed 's/[^a-zA-Z0-9._-]/-/g' | tr '[:upper:]' '[:lower:]')
  
  # Ensure we have a non-empty branch name
  if [ -z "$safe_branch_name" ]; then
    safe_branch_name="branch"
  fi
  
  # Safety check: Validate branch name length
  if [ ${#safe_branch_name} -gt 100 ]; then
    echo "⚠️ WARNING: Very long branch name after sanitization: ${#safe_branch_name} characters"
    echo "   This may cause issues with some systems (Docker tag limit is 128 chars total)"
  fi
  
  # Safety check: Check if container tag already exists in registry
  local full_tag="$REGISTRY_URL/$PROJECT_PATH/securemail-$1:$safe_branch_name"
  echo "🔍 Checking for existing container tag: $safe_branch_name"
  if docker manifest inspect "$full_tag" >/dev/null 2>&1; then
    echo "⚠️ WARNING: Container tag '$safe_branch_name' already exists in registry"
    echo "   This build will overwrite the existing image"
  else
    echo "✅ Container tag is available"
  fi
  
  echo "✅ MATCH: Building $1 for feature branch as '$safe_branch_name'"
  echo "   Original branch: '$CI_COMMIT_REF_NAME'"
  echo "   Sanitized name: '$safe_branch_name'"
  echo "   Final tag: '$safe_branch_name'"
  
  # shellcheck disable=SC2086  # We want word splitting for build args
  timeout "$DOCKER_BUILD_TIMEOUT" docker build $2 \
    --network=host --progress=plain \
    -t "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:$safe_branch_name" "./$1"
  timeout "$REGISTRY_TIMEOUT" docker push "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:$safe_branch_name"
  echo "✅ $1 feature branch build & push to CR completed"
}

build_version_tag() {
  echo "=== Checking Tag Condition ==="
  echo "Current tag: ${CI_COMMIT_TAG:-none}"
  echo "Checking if tag is present..."
  
  if [ -n "$CI_COMMIT_TAG" ]; then
    echo "✅ MATCH: Building $1 for tag: $CI_COMMIT_TAG"
    
    echo "Build with specific version tag (always build this)"
    # shellcheck disable=SC2086  # We want word splitting for build args
    timeout "$DOCKER_BUILD_TIMEOUT" docker build $2 \
      --network=host --progress=plain \
      -t "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:$CI_COMMIT_TAG" "./$1"
    timeout "$REGISTRY_TIMEOUT" docker push \
      "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:$CI_COMMIT_TAG"
    echo "✅ $1 specific version $CI_COMMIT_TAG pushed"
    
    echo "Check if we should update 'latest' tag using semantic version comparison"
    echo "=== Semantic Version Check for 'latest' tag ==="
    
    echo "Get current latest with error handling"
    if ! CURRENT_LATEST=$(get_latest_version_tag "$CI_COMMIT_TAG"); then
      echo "❌ ERROR: Failed to determine latest version tag"
      echo "Falling back to safe mode: skipping 'latest' tag update"
    else
      echo "Current latest version tag in git: $CURRENT_LATEST"
      echo "New version tag: $CI_COMMIT_TAG"
      
      echo "Compare versions with error handling"
      if compare_semver "$CI_COMMIT_TAG" "$CURRENT_LATEST"; then
        echo "✅ New version $CI_COMMIT_TAG is semantically newer than $CURRENT_LATEST"
        echo "Building and pushing 'latest' tag..."
        # shellcheck disable=SC2086  # We want word splitting for build args
        if timeout "$DOCKER_BUILD_TIMEOUT" docker build $2 \
          --network=host --progress=plain \
          -t "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:latest" "./$1"; then
          if timeout "$REGISTRY_TIMEOUT" docker push "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:latest"; then
            echo "✅ $1 'latest' tag updated to $CI_COMMIT_TAG"
          else
            echo "❌ ERROR: Failed to push 'latest' tag to registry"
            exit 1
          fi
        else
          echo "❌ ERROR: Failed to build 'latest' tag"
          exit 1
        fi
      else
        COMPARE_RESULT=$?
        if [ $COMPARE_RESULT -eq 1 ]; then
          echo "ℹ️  Version $CI_COMMIT_TAG is not newer than current latest $CURRENT_LATEST"
          echo "Skipping 'latest' tag update to prevent downgrade"
        else
          echo "❌ ERROR: Semantic version comparison failed (exit code: $COMPARE_RESULT)"
          echo "Falling back to safe mode: skipping 'latest' tag update"
        fi
      fi
    fi
    
    echo "✅ $1 tag build & push to CR completed"
  else
    echo "❌ SKIP: No tag present"
  fi
}

build_test_branch() {
  echo "=== Checking Test Branch Condition ==="
  echo "Current branch: '$CI_COMMIT_REF_NAME'"
  echo "Checking if branch equals 'ci-pipeline-test-temp'..."
  
  if [ "$CI_COMMIT_REF_NAME" = "ci-pipeline-test-temp" ]; then
    echo "✅ MATCH: Building $1 for test branch"
    # shellcheck disable=SC2086  # We want word splitting for build args
    timeout "$DOCKER_BUILD_TIMEOUT" docker build $2 \
      --network=host --progress=plain \
      -t "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:test" "./$1"
    timeout "$REGISTRY_TIMEOUT" docker push "$REGISTRY_URL/$PROJECT_PATH/securemail-$1:test"
    echo "✅ $1 test build & push to CR completed"
  else
    echo "❌ SKIP: Branch '$CI_COMMIT_REF_NAME' != 'ci-pipeline-test-temp'"
  fi
} 