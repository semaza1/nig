<?php
// pages/login.php - handles login form and authentication
session_start();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // simple input handling
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Andika email/telephone na ijambo ry\'ibanga.';
    } else {
        // connect to DB (returns mysqli)
        $mysqli = require __DIR__ . '/../config/db.php';

        // try find by email or phone1 or nid_passport
        $sql = "SELECT id, names, email, password, is_member, is_admin FROM users WHERE email = ? OR phone1 = ? OR nid_passport = ? LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sss', $identifier, $identifier, $identifier);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res->fetch_assoc();
            $stmt->close();

            if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
                // success
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['names'] = $user['names'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['is_member'] = (int)$user['is_member'];
                $_SESSION['is_admin'] = (int)$user['is_admin'];

                // redirect based on role
                if ($_SESSION['is_admin']) {
                    header('Location: admin/dashboard.html');
                    exit;
                }
                if ($_SESSION['is_member']) {
                    header('Location: member/dashboard.html');
                    exit;
                }
                header('Location: non-member/dashboard.html');
                exit;
            } else {
                $error = 'Email/telefoni cyangwa ijambo ry\'ibanga ntibiriyo.';
            }
        } else {
            $error = 'Ikibazo mu gusoma database.';
        }
    }
}
?>
<!doctype html>
<html lang="rw">
  <head>
    <meta charset="utf-8" />
    <title>Injira - NIG Ikimina</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: "#2F6B4F",
              "primary-dark": "#1F4D3A",
              accent: "#E89C2C",
              brown: "#6B4A2D",
              "bg-soft": "#F8FAF9",
            },
          },
        },
      };
    </script>
    <link rel="stylesheet" href="../styles.css" />
  </head>
  <body class="min-h-screen bg-[color:var(--color-bg)]">
    <div class="flex min-h-screen flex-col md:flex-row">
      <!-- Left info -->
      <section class="hidden flex-1 flex-col justify-between bg-primary-dark px-8 py-8 text-white md:flex">
        <div>
          <div class="flex items-center gap-2">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-lg font-bold">N</div>
            <div>
              <p class="text-sm font-semibold">NIG Ikimina</p>
              <p class="text-xs text-emerald-100/80">Ikoranabuhanga ryo gucunga Ikimina</p>
            </div>
          </div>
          <div class="mt-10 space-y-4 text-sm text-emerald-50">
            <p class="text-base font-semibold">“Gucunga imigabane, inguzanyo n’inyungu mu buryo bwizewe.”</p>
            <p>Buri munyamuryango abona amakuru ye mu buryo bunoze. Abayobozi nabo bakabona raporo z’ingenzi zifasha gufata ibyemezo byiza.</p>
          </div>
        </div>
        <p class="text-xs text-emerald-100/80">© <span id="year"></span> NIG Ikimina</p>
      </section>

      <!-- Right auth form -->
      <main class="flex min-h-screen flex-1 items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
          <a href="../index.html" class="inline-flex items-center text-xs font-medium text-slate-500 hover:text-primary">← Subira ku rupapuro rw’itangiriro</a>
          <div class="mt-4 card">
            <h1 class="text-lg font-semibold text-primary-dark">Injira muri konti yawe</h1>
            <p class="mt-1 text-xs text-slate-600">Injira ukoresheje nimero ya telefoni cyangwa email washyize muri sisitemu.</p>
            <?php if ($error): ?>
            <div class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700"><?=htmlspecialchars($error)?></div>
            <?php endif; ?>
            <form class="mt-6 space-y-4" method="POST" action="" novalidate>
              <div>
                <label class="block text-xs font-medium text-slate-700">Nimero ya Telefoni / Email</label>
                <input
                  name="identifier"
                  type="text"
                  value="<?=isset($identifier) ? htmlspecialchars($identifier) : ''?>"
                  class="mt-1 w-full rounded-lg border border-[color:var(--color-border)] bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  placeholder="Urugero: 07.. cyangwa email"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-700">Ijambo ry’ibanga</label>
                <input
                  name="password"
                  type="password"
                  class="mt-1 w-full rounded-lg border border-[color:var(--color-border)] bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  placeholder="Shyiramo ijambo ry’ibanga"
                />
              </div>
              <div class="flex items-center justify-between text-xs">
                <label class="flex items-center gap-2 text-slate-600">
                  <input type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300 text-primary focus:ring-primary" />
                  <span>Nyibuka kuri iri koranabuhanga</span>
                </label>
                <button type="button" class="text-primary hover:text-primary-dark">Wibagiwe ijambo ry’ibanga?</button>
              </div>
              <button type="submit" class="btn-primary w-full justify-center">Injira muri konti</button>
            </form>
            <p class="mt-4 text-center text-xs text-slate-600">Nta konti uragira?
              <a href="signup.html" class="font-semibold text-primary hover:text-primary-dark">Iyandikishe ubungubu</a>
            </p>
          </div>
        </div>
      </main>
    </div>
    <script>document.getElementById("year").textContent = new Date().getFullYear();</script>
  </body>
</html>
