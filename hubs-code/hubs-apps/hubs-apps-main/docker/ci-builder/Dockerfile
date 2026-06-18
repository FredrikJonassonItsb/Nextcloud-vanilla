# CI builder image — the platform CI toolchain AND the platform tree itself, baked in, so app
# pipelines run with zero per-job apt install AND zero runtime platform clone. Base
# cimg/php:8.3-node (same base as the dev-builder, so the CI toolchain stays a subset of dev's);
# add only the packages the base lacks that assemble + package need (quilt + rsync; pigz for
# parallel tar compression in package.sh). Runs as root: CI is ephemeral + isolated, and root
# owns the GitLab cache/artifact-restored files, so no chown is needed.
#
# The image is TAGGED by the hubs-apps ref it is built from (CI_COMMIT_REF_NAME — branch or tag;
# see the build-image job in .gitlab-ci.yml), and .build_env pulls ci-builder:$PLATFORM_REF — so
# the baked platform always matches the platform version each app pins, and the image is the
# single source of truth (no clone, no PLATFORM_REPO).
#
# Built with context = repo root + this dir's Dockerfile.dockerignore (BuildKit picks the
# per-Dockerfile ignore over the root .dockerignore, which is the nextcloud image's whitelist and
# would otherwise bake nothing). /tmp/platform matches the path CI jobs pass (PLATFORM_ROOT, the
# assemble.sh path); /platform is the symlink the Makefile recipes hardcode.
FROM cimg/php:8.3-node

USER root

RUN apt-get update \
 && apt-get install -y --no-install-recommends quilt rsync pigz \
 && rm -rf /var/lib/apt/lists/*

COPY . /tmp/platform
RUN ln -sfn /tmp/platform /platform
