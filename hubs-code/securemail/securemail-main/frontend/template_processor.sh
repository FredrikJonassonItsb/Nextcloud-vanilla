#!/usr/bin/env bash
#
# -----------------------------------------------------------------------------
# Template Processing Script
# -----------------------------------------------------------------------------

set -Eeuo pipefail
# -x: Print each command to stderr before executing it (bash "trace" mode)
# -E: Inherit ERR traps in functions and subshells
# -e: Exit immediately if a command exits with a non-zero status
# -u: Treat unset variables as errors
# -o pipefail: Pipeline returns the exit code of the first failing command

# -----------------------------------------------------------------------------
# Cleanup function for temporary files
# -----------------------------------------------------------------------------
cleanup_temp_file() {
  echo "[DEBUG] cleanup_temp_file called."
  if [[ -n "${TMP_FILE:-}" && -f "${TMP_FILE}" ]]; then
    echo "[DEBUG] Removing temporary file: ${TMP_FILE}"
    rm -f "${TMP_FILE}"
  fi
}
trap cleanup_temp_file EXIT INT TERM

# -----------------------------------------------------------------------------
# Main Substitution Logic
# -----------------------------------------------------------------------------
main() {
  echo "[INFO] Starting template processing..."
  echo "[DEBUG] Script invoked with arguments: $*"
  
  # readarray (or mapfile) will convert multiline environment variable into an array
  declare -a FILES_TO_PROCESS=()
  if [[ -n "${ENV_FILES_TO_PROCESS:-}" ]]; then
    echo "[DEBUG] Found ENV_FILES_TO_PROCESS environment variable. Parsing it..."
    # Each line in ENV_FILES_TO_PROCESS becomes one element of FILES_TO_PROCESS
    readarray -t FILES_TO_PROCESS <<< "${ENV_FILES_TO_PROCESS}"
  else
    echo "[INFO] ENV_FILES_TO_PROCESS is empty or not set."
  fi

  if [[ ${#FILES_TO_PROCESS[@]} -eq 0 ]]; then
    echo "[INFO] No files are defined in ENV_FILES_TO_PROCESS. Nothing to do."
    exit 0
  fi

  echo "[DEBUG] Current environment (filtered by SECUREMAIL_ for brevity):"
  env | grep '^SECUREMAIL_' || true

  # -----------------------------------------------------------------------------
  # Process each line in FILES_TO_PROCESS
  # -----------------------------------------------------------------------------
  for entry in "${FILES_TO_PROCESS[@]}"; do
    # Reset TMP_FILE each iteration
    TMP_FILE=""

    echo
    echo "=============================================================="
    echo "[DEBUG] Processing array entry: ${entry}"

    # Parse the "src=... dst=... vars=..." syntax
    SRC="$(echo "${entry}" | sed -n 's/.*src=\([^ ]*\).*/\1/p')"
    DST="$(echo "${entry}" | sed -n 's/.*dst=\([^ ]*\).*/\1/p')"
    VARS="$(echo "${entry}" | sed -n 's/.*vars=\([^ ]*\).*/\1/p')"

    echo "[INFO] Source file:      ${SRC}"
    echo "[INFO] Destination file: ${DST}"
    echo "[INFO] Variables list:   ${VARS}"

    # Check if the source file exists
    if [[ ! -f "${SRC}" ]]; then
      echo "[ERROR] Source file ${SRC} does not exist. Skipping this entry."
      continue
    fi

    # Convert comma-separated VARS into $VAR1 $VAR2 ... for envsubst
    ENVVAR_PLACEHOLDERS=""
    if [[ -n "${VARS}" ]]; then
      # Replace commas with spaces, then prepend each variable with $
      # E.g. "VAR1,VAR2" => "$VAR1 $VAR2"
      ENVVAR_PLACEHOLDERS="$(echo "${VARS}" | sed 's/,/ \$/g')"
      ENVVAR_PLACEHOLDERS="\$${ENVVAR_PLACEHOLDERS}"
    fi

    # Optional: log each variable's actual value to confirm it's set
    # CAREFUL: Do not log secrets in production.
    echo "[DEBUG] Checking actual environment variable values:"
    if [[ -n "${VARS}" ]]; then
      for varName in $(echo "${VARS}" | tr ',' ' '); do
        val="${!varName:-<NOT SET>}"
        echo "   ${varName} = ${val}"
      done
    else
      echo "   [DEBUG] No variables specified; envsubst will try to substitute *all* environment variables."
    fi

    # Perform the substitution
    perform_substitution() {
      echo "[DEBUG] Running: envsubst '${ENVVAR_PLACEHOLDERS}' < ${SRC} > '$1'"
      envsubst "${ENVVAR_PLACEHOLDERS}" < "${SRC}" > "$1"
    }

    # If SRC == DST, write via a temp file to avoid clobbering the source
    if [[ "${SRC}" == "${DST}" ]]; then
      echo "[WARN] Source and destination are the same. Using a temp file for safe substitution."
      TMP_FILE="$(mktemp -t envsubst.XXXXXX)"
      perform_substitution "${TMP_FILE}"
      echo "[DEBUG] Substitution complete. Overwriting original file with temp file."
      mv "${TMP_FILE}" "${DST}"
      TMP_FILE=""
    else
      echo "[DEBUG] Source and destination differ; substituting directly to destination."
      perform_substitution "${DST}"
    fi

    echo "[INFO] Successfully processed: ${SRC} -> ${DST}"
    echo "=============================================================="
  done

  echo "[INFO] All template files processed."
}

main "$@"
