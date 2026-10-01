# Print-file service on the Synology NAS

The designer's **Approve design** turns a design into the print-ready CMYK PDF
through this service. It runs on the NAS in a container and is reached by
WordPress.com through **Tailscale Funnel** (a public HTTPS address for this one
service; no router port forwarding).

Needs: a NAS with **Container Manager** (DSM 7.2+), 4 GB RAM or more, and the
**Tailscale** package signed in to your tailnet.

1. **GitHub token for the private image.** github.com → Settings → Developer
   settings → Personal access tokens → Tokens (classic) → scope
   `read:packages` only.
2. **Log the NAS in to GitHub's registry** (SSH to the NAS, then):
   `sudo docker login ghcr.io -u reemprimeco-cloud` and paste the token.
3. **Container Manager → Project → Create**: folder
   `/volume1/docker/binder-render`, source "Create compose.yaml", paste
   `compose.yaml` from this folder, fill in the two CHANGE values, build.
4. **Publish it with Funnel** (SSH): `sudo tailscale funnel --bg 8787`.
   It prints the public `https://….ts.net` address. If Funnel is not yet
   allowed for the tailnet, the command prints a link to turn it on.
   Put that address in PUBLIC_BASE_URL (step 3) and restart the project.
5. **WordPress → Settings → Prime Designer**: Render service URL = the
   Funnel address, Shared secret = BINDER_SECRET. **Test connection** must
   say Ghostscript, ICC profile and print page are all "yes".

Updating: GitHub rebuilds the image when the service changes; in Container
Manager → Project → binder-render → Action → Build (pulls `latest`).
