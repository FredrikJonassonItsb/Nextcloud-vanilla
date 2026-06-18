#!/usr/bin/env bash

# Define the Docker command.
# This allows us to prepend 'sudo' if necessary without breaking the command syntax.
DOCKER=(docker)

# Check if Docker commands can be run without sudo by attempting to run 'docker stats'.
# Redirect output and errors to '/dev/null' to suppress them.
if ! "${DOCKER[@]}" stats --no-stream > "/dev/null" 2>&1; then
    # If the command fails, update DOCKER to include 'sudo'.
    DOCKER=(sudo docker)
fi

# Define patterns for inclusion and exclusion.
# INCLUDE_PATTERN: Containers and volumes with names matching this pattern will be included.
# EXCLUDE_PATTERN: Containers and volumes with names matching this pattern will be excluded.
INCLUDE_PATTERN='sdkmc'
EXCLUDE_PATTERN='sdkmcmw'

# Initialize an array to hold container IDs that match the criteria.
mapfile -t CONTAINERS < <(
    # List all containers with their IDs and Names.
    "${DOCKER[@]}" ps --all --format '{{.ID}} {{.Names}}' |
    # Include lines that match the INCLUDE_PATTERN (case-insensitive).
    grep -i "${INCLUDE_PATTERN}" |
    # Exclude lines that match the EXCLUDE_PATTERN (case-insensitive).
    grep -vi "${EXCLUDE_PATTERN}" |
    # Extract the container IDs (the first field in each line).
    awk '{print $1}'
)

# Initialize an array to hold volume names that match the criteria.
mapfile -t VOLUMES < <(
    # List all volumes with their Names.
    "${DOCKER[@]}" volume ls --format '{{.Name}}' |
    # Include names that match the INCLUDE_PATTERN (case-insensitive).
    grep -i "${INCLUDE_PATTERN}" |
    # Exclude names that match the EXCLUDE_PATTERN (case-insensitive).
    grep -vi "${EXCLUDE_PATTERN}"
)

# Check if there are any containers to stop and remove them.
if [ "${#CONTAINERS[@]}" -gt 0 ]; then
    # Stop the matching containers.
    "${DOCKER[@]}" container stop --time 0 "${CONTAINERS[@]}"
    # Remove the stopped containers.
    "${DOCKER[@]}" container rm "${CONTAINERS[@]}"
fi

# Check if there are any volumes to remove.
if [ "${#VOLUMES[@]}" -gt 0 ]; then
    # Remove the matching volumes.
    "${DOCKER[@]}" volume rm "${VOLUMES[@]}"
fi

# Warn the user about docker system prune.
echo ""
echo "WARNING: You are about to run 'docker system prune --all --force --volumes'."
echo "This command will remove:"
echo "- All stopped containers"
echo "- All networks not used by at least one container"
echo "- All dangling images (images not tagged and not used by any container)"
echo "- All build cache"
echo "- All unused volumes"
echo
echo "This operation is irreversible and will free up disk space by removing unused Docker data."
echo "Please ensure that you have backed up any important data before proceeding."

# Prompt the user for confirmation.
read -r -p "Do you want to proceed with the system prune? (y/N): " confirm
if [[ "$confirm" =~ ^[Yy]$ ]]; then
    # Clean up unused Docker resources.
    "${DOCKER[@]}" system prune --all --force --volumes
    echo "Docker system prune completed."
else
    echo "Docker system wide prune operation cancelled, SDKMC related cleanup is still performed."
    echo ""
    exit 1
fi
