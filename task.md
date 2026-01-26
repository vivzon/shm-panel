# Task: Update Utility Enhancements

- [ ] **Nginx Default Server**
    - [ ] Add check and creation of `000-default` site in `update.sh`.
- [ ] **Log Directories**
    - [ ] Add permission repair loop for `/var/www/clients/*/logs`.
- [ ] **Schema Sync**
    - [ ] Update `update.sh` to include all table definitions from `install.sh`.
- [ ] **Service Refinements**
    - [ ] Ensure `nginx -t` is run before reloads.
