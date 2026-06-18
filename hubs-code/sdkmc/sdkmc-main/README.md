<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: CC0-1.0
-->

# SDK Message Client

See `appinfo/info.xml` for an overview.

## ITSL MC/MW Interface

Interaction between MC and MW is server-to-server, i.e., the MC Backend will make the requests towards
the MW on behalf of the MC Frontend. As such, most of the MC Backend acts as a proxy towards the MW.

### User Management

In order for the MC Backend to communicate with the MW, it has to retrieve a User and its
associated Token. This is handled by the following steps:

1. The MC Backend POSTs a UserDto based on the current NC User over HTTPS to the MW. It includes
   the NC RequestId and the HMAC for the NC RequestId. The MC Backend will verify the MW via a
   successful HTTPS connection. The MW will verify the MC Backend by checking the provided HMAC.
   (mTLS would be better, but is currently not considered to be security critical.) The UserDto
   can specify normal or special privilege, where the latter allows the user to bypass all MW
   Security Functions. NC Super Administrators will have special privilege.
2. The MW creates or updates a User matching the given UserDto and responds with it and a Token
   which can be used to authenticate the user in future calls.
3. The MC Backend saves the Token returned.

## Developing the app

### To get a Docker-based development environment running:

```sh
cd /dst/dir  # Go to your destination directory for this project
git clone git@gitlab.itsl.se:itsl/sdkmc.git  # Clone the git repo
cd sdkmc  # Enter the project folder
./bin/run-dev-env.sh  # Execute the development environment setup script, you can rerun it at any time if something is not working
```
Make sure docker and docker compose are installed on your system. `curl https://get.docker.com | sh`

It comes with an SSH server listening at `127.0.0.1:2222`, it can be accessed with "developer:developer" and Nextcloud via `http://127.0.0.1:8080` with "admin:admin"
```sh
ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null developer@127.0.0.1 -p 2222
```

### To clean your development environment:

```sh
cd /path/to/sdkmc
./bin/prune-docker.sh  # Prune Docker containers, volumes and networks
cd ..  # Move out of project folder
rm -r /path/to/sdkmc  # Remove project folder
```
Now you can rerun the setup script `run-dev-env.sh`

### Publish to App Store

First get an account for the [App Store](http://apps.nextcloud.com/) then run:

```sh
ssh -p "2222" "developer@localhost"
cd "/var/www/html/apps-extra/sdkmc"
make && make "appstore"
```

The archive is located in `build/artifacts/appstore` and can then be uploaded to the App Store.

## Handling Nextcloud versions

It is possible to set the Nextcloud version by using `NEXTCLOUD_VERSION` with the `docker-compose.yml`
file. To permanently update it for Dev Envs, update the Compose file, for temporary changes set the
environment variable. If an uplift of the Nextcloud version requires changes to the MC which are not
backwards compatible, `appinfo/info.xml` must also be updated.

It can also be necessary be necessary to update forwards compatibility too, the span of min to max
version should be properly tested and officially supported.
