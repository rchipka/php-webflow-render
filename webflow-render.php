<?php
function webflow_render($keys) {
  context($keys)->enter()->exit();
}

function webflow_init($jsonFile) {
  $elements = @json_decode(file_get_contents($jsonFile), true);

  if (!$elements) {
    return;
  }

  $templates = [];

  foreach ($elements as $e) {
    $templates[$e['class']] = $e['html'];
  }

  $loader = new Twig_Loader_Array($templates);

  $twig = new Twig_Environment($loader);

  $twig->addFunction(new Twig_Function('webflow_render', 'webflow_render'));


  $GLOBALS['wf_element_index'] = [];

  add_action('enter_context', function () use ($elements, $twig) {
    $max_similarity = 0;
    $matches = [];

    foreach ($elements as $e) {
      $similarity = context()->match($e['keys']);

      if (!$matches['' . $similarity]) {
        $matches['' . $similarity] = [];
      }

      $matches['' . $similarity][] = $e;

      if ($similarity > $max_similarity) {
        $max_similarity = $similarity;
      }
    }

    if (!$max_match <= 0) {
      return;
    }

    $match = $matches[$max_similarity];

    if (sizeof($match) < 1) {
      return;
    }

    error_log(json_encode($match));

    $key = md5(implode('-', array_map(function ($e) {
      return $e['class'];
    }, $match)));

    if (!$GLOBALS['wf_element_index'][$key]) {
      $GLOBALS['wf_element_index'][$key] = 0;
    }

    $index = $GLOBALS['wf_element_index'][$key];


    $element = $match[$index % sizeof($match)];

    error_log($element['class'] . ' (' . $s . ') => ' . $index . ' / ' . sizeof($match));

    // echo $index;

    echo $twig->render($element['class'], array('title' => 'test'));

    $GLOBALS['wf_element_index'][$key]++;
  });
}
