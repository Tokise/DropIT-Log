<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT Logistic Suite – Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; display:flex; align-items:center; justify-content:center; background: #0f172a; }
    .login-card { width:100%; max-width:420px; }
  </style>
  <script>
    // If already logged in (session cookie), try /api/me.php and redirect
    (async function(){
      try {
        const r = await fetch('api/me.php');
        if (r.ok) {
          const data = await r.json();
          const u = data.data || data;
          // Check if user is a supplier
          if (u.supplier_id) {
            window.location.replace('supplier_portal.php');
          } else {
            const target = (u.role === 'admin') ? 'admin.php' : `${u.module||'psm'}.php`;
            window.location.replace(target);
          }
        }
      } catch(e) {}
    })();
  </script>
</head>
<body>
  <div class="card shadow login-card">
    <div class="card-body p-4">
      <div class="text-center mb-3">
        <i class="fa-solid fa-warehouse fa-2x text-primary"></i>
        <h1 class="h4 mt-2 mb-0">DropIT Logistic Suite</h1>
        <div class="text-muted">Sign in to continue</div>
      </div>
      <form id="loginForm">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" id="email" placeholder="you@company.com" required />
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" class="form-control" id="password" placeholder="••••••••" required />
        </div>
        <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-right-to-bracket me-2"></i>Sign In</button>
      </form>
    </div>
    <div class="card-footer text-center text-muted small">© <span id="year"></span> DropIT</div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('year').textContent = new Date().getFullYear();
    document.getElementById('loginForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const email = document.getElementById('email').value.trim();
      const password = document.getElementById('password').value;
      try {
        const r = await fetch('api/auth.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({email, password})});
        if (!r.ok) throw new Error(await r.text());
        const data = await r.json();
        const redirectUrl = data.data.redirect || 'index.php';
        window.location.href = redirectUrl;
      } catch(err) {
        alert('Login failed');
      }
    });
  </script>
</body>
</html>
