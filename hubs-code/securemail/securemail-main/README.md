# Secure Mail
This application consists of a backend and a frontend.

The backend is a Node.js application that uses the Express framework. It is responsible for handling the incoming requests and sending the responses. It is run in Docker.

The frontend is a Vue.js application that uses the Material-UI library. It is responsible for displaying the email list and the email content. It is compiled to a static HTML file and served by any static file server.

## Development
For the backend, copy `.env.example` to `.env` and set the environment variables. Then, run the backend:
```bash
$ pnpm install
$ pnpm run dev
```

For the frontend:
```bash
$ pnpm install
$ pnpm run dev
```

## Deployment
Build the Docker image for the backend:
```bash
$ cd backend
$ docker build -t secure-mail-backend .
$ docker run \
   --restart unless-stopped \
   --detach \
   --publish 127.0.0.1:3000:3000 \
   --env IMAP_HOST=<your IMAP server> \
   --env IMAP_PORT=<your IMAP port> \
   --env SMTP_HOST=<your SMTP server> \
   --env SMTP_PORT=<your SMTP port> \
   --env KEYCLOAK_REALM=<your Keycloak realm> \
   --name backend \
   secure-mail-backend
```

Build the frontend:
```bash
$ cd frontend
$ pnpm run build
```

The compiled frontend is in `dist. It can be served by any static file server, but make sure to serve `/api` and `/ws` by the backend.
