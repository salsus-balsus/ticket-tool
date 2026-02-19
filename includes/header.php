<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - Ticket Tool' : 'Ticket Tool' ?></title>
    <link href="./assets/css/tabler.min.css" rel="stylesheet"/>
    <link href="./assets/css/tabler-flags.min.css" rel="stylesheet"/>
    <link href="./assets/css/tabler-payments.min.css" rel="stylesheet"/>
    <link href="./assets/css/tabler-vendors.min.css" rel="stylesheet"/>
    <link href="./assets/css/demo.min.css" rel="stylesheet"/>
    <style>
      @import url('https://rsms.me/inter/inter.css');
      /* Fix: overflow-y erzwingt den Scrollbalken immer, stoppt horizontales Springen */
      html { zoom: 0.8; overflow-y: scroll; } 
      .modal-backdrop { background-color: transparent !important; }
      :root {
      	--tblr-font-sans-serif: 'Inter', -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif;
      }
      body {
      	font-family: 'Inter', sans-serif;
      	background-color: #f4f6fa;
      	font-feature-settings: "cv03", "cv04", "cv11";
      }
    </style>
    <?php if (!empty($pageHeadExtra)) echo $pageHeadExtra; ?>
</head>
<body class="d-flex flex-column">
    <div class="page">
        <?php require_once 'includes/navigation.php'; ?>