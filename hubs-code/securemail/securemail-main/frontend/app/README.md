# Secure Mail
This application consists of a backend and a frontend.

The frontend is a Vue.js application that uses the Material-UI library. It is responsible for displaying the email list and the email content. It is compiled to a static HTML file and served by any static file server.

## Development
For the frontend:
```bash
$ pnpm install
$ pnpm run dev
```

## Deployment
Build the frontend:
```bash
$ cd frontend
$ pnpm run build
```

The compiled frontend is in `dist. It can be served by any static file server, but make sure to serve `/api` and `/ws` by the backend.
