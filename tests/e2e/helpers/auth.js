export async function login(page, { username, password }) {
  await page.goto('/login')

  const userInput = page.locator('#user').or(page.locator('input[name="user"]'))
  const passInput = page.locator('#password').or(page.locator('input[name="password"]'))

  await userInput.fill(username)
  await passInput.fill(password)

  const submit = page.locator('button[type="submit"]').first()
  await submit.click()

  // Wait until we are logged in (header user menu exists)
  await page.waitForLoadState('networkidle')
}

export function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`]
  const p = process.env[`NC_${role}_PASS`]
  if (!u || !p) {
    throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`)
  }
  return { username: u, password: p }
}

