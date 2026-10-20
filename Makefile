# Makefile for ArbeitszeitCheck app release

app_name = arbeitszeitcheck
build_dir = build
release_dir = $(build_dir)/release
version = $(shell grep '^\s*<version>' appinfo/info.xml | sed 's/.*<version>\([0-9.]*\)<\/version>.*/\1/' | head -1)
archive_name = $(app_name)-$(version).tar.gz
archive_path = $(release_dir)/$(archive_name)
occ = ../../occ
# Override with APP_CERT_KEY_PATH / APP_CERT_CRT_PATH (see release/APPSTORE-RELEASE.md, ready2publish/APPSTORE-RELEASE.md).
SIGN_KEY := $(if $(strip $(APP_CERT_KEY_PATH)),$(APP_CERT_KEY_PATH),$(HOME)/.nextcloud/certificates/$(app_name).key)
SIGN_CRT := $(if $(strip $(APP_CERT_CRT_PATH)),$(APP_CERT_CRT_PATH),$(HOME)/.nextcloud/certificates/$(app_name).crt)
ready2publish_sign = ../../ready2publish/scripts/sign-nextcloud-appstore-archive.sh

.PHONY: release verify-release verify-signature-manifest sign-release release-signed clean test-security-role-gating-docker

release:
	@echo "Building $(app_name) v$(version)..."
	@mkdir -p $(release_dir)
	@staging=$$(mktemp -d) && \
		mkdir -p "$$staging/$(app_name)" && \
		rsync -a --exclude='.git' --exclude='$(build_dir)' --exclude='.github' \
			--exclude='node_modules' --exclude='node_modules.broken-*' --exclude='tests' --exclude='.phpunit.result.cache' \
			--exclude='test-results' --exclude='scripts' --exclude='release/*.tar.gz' --exclude='release/*.asc' \
			--exclude='appinfo/signature.json' --exclude='infection.json5' --exclude='infection-security.json5' \
			--exclude='vendor' --exclude='docs' \
			--exclude='phpunit.xml' --exclude='phpunit.cov.xml' --exclude='playwright.config.js' --exclude='vitest.config.js' --exclude='.auth' \
			./ "$$staging/$(app_name)/" && \
		tar --mode='u+rwX,go+rX,go-w' --owner=0 --group=0 --numeric-owner -czf $(archive_path) -C "$$staging" $(app_name) && \
		rm -rf "$$staging"
	@echo "Created $(archive_path)"

verify-release:
	@test -f $(archive_path) || (echo "Error: Run 'make release' first"; exit 1)
	@if tar -tzf $(archive_path) | grep -Eq '/(\.git/|node_modules(/|\.broken-)|build/|tests/|test-results/|scripts/|vendor/|docs/)'; then \
		echo "Error: release archive contains forbidden development paths"; \
		tar -tzf $(archive_path) | grep -E '/(\.git/|node_modules(/|\.broken-)|build/|tests/|test-results/|scripts/|vendor/|docs/)' || true; \
		exit 1; \
	fi
	@echo "Release archive layout looks clean."

verify-signature-manifest:
	@test -f $(archive_path) || (echo "Error: Run 'make release-signed' first"; exit 1)
	@python3 ../../ready2publish/scripts/verify-app-signature-archive.py $(app_name) $(archive_path)
	@tmpdir=$$(mktemp -d) && \
		trap 'rm -rf "$$tmpdir"' EXIT && \
		tar -xzf $(archive_path) -C "$$tmpdir" "$(app_name)/appinfo/signature.json" && \
		sig="$$tmpdir/$(app_name)/appinfo/signature.json" && \
		if ! test -f "$$sig"; then \
			echo "Error: signature.json missing from signed archive"; \
			exit 1; \
		fi && \
		if grep -Eq '"([^"]*/)?(\.git|node_modules|build|tests|test-results|scripts)\\/' "$$sig"; then \
			echo "Error: signature.json references forbidden development paths"; \
			grep -E '"([^"]*/)?(\.git|node_modules|build|tests|test-results|scripts)\\/' "$$sig" || true; \
			exit 1; \
		fi
	@echo "Signature manifest sanity check passed."

clean:
	rm -rf $(build_dir)

# Generate tarball signature for App Store upload (single-line base64; verifies RSA length + key matches cert)
# Paste the output into the App Store upload form's "Signature" field
sign-tarball:
	@test -f $(archive_path) || (echo "Error: Run 'make release' first"; exit 1)
	@test -f $(ready2publish_sign) || (echo "Error: Missing $(ready2publish_sign)"; exit 1)
	@APPSTORE_SIGNING_KEY="$(SIGN_KEY)" APPSTORE_SIGNING_CERT="$(SIGN_CRT)" bash "$(ready2publish_sign)" $(app_name) $(archive_path)

# Sign the release archive payload with Nextcloud app signature
# This signs the extracted archive tree (not your local dev checkout), then repacks it.
# Generate cert: openssl req -nodes -newkey rsa:4096 -keyout ~/.nextcloud/certificates/arbeitszeitcheck.key -out ~/.nextcloud/certificates/arbeitszeitcheck.csr -subj "/CN=arbeitszeitcheck"
# Store signed cert as ~/.nextcloud/certificates/arbeitszeitcheck.crt
sign-release: verify-release
	@test -f "$(SIGN_KEY)" || (echo "Error: Missing signing key: $(SIGN_KEY) (set APP_CERT_KEY_PATH or install under ~/.nextcloud/certificates/ — see https://github.com/nextcloud/app-certificate-requests)"; exit 1)
	@test -f "$(SIGN_CRT)" || (echo "Error: Missing certificate: $(SIGN_CRT) (set APP_CERT_CRT_PATH or store at ~/.nextcloud/certificates/$(app_name).crt)"; exit 1)
	@test -f $(occ) || (echo "Error: occ not found at $(occ). Override with 'make sign-release occ=/path/to/occ'"; exit 1)
	@staging=$$(mktemp -d) && \
		trap 'rm -rf "$$staging"' EXIT && \
		tar -xzf $(archive_path) -C "$$staging" && \
		php $(occ) integrity:sign-app \
			--privateKey="$(SIGN_KEY)" \
			--certificate="$(SIGN_CRT)" \
			--path="$$staging/$(app_name)" && \
		tar --mode='u+rwX,go+rX,go-w' --owner=0 --group=0 --numeric-owner -czf $(archive_path) -C "$$staging" $(app_name)
	@echo "Signed archive updated at $(archive_path)"

release-signed: release sign-release verify-signature-manifest
	@echo "Release build + Nextcloud signature complete."

test-security-role-gating-docker:
	@bash scripts/test-security-role-gating-docker.sh
