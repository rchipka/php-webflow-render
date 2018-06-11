<?php
class RenderContext {
  public function __get($property) {
    error_log($property);
    if (property_exists($this, $property)) {
    }

    return 'test';
  }

  public function __set($property, $value) {
    if (property_exists($this, $property)) {
    }

    return $this;
  }
}
function webflow_render($keys) {
  context($keys)->enter()->exit();
}

function webflow_init($jsonFile) {
  $elements = @json_decode(file_get_contents($jsonFile));

  if (!$elements) {
    return;
  }

  $templates = [];

  foreach ($elements as $i => $e) {
    $e->class = $e->class . ' - ' . $i;
    
    $templates[$e->class] = $e->html;
  }

  $loader = new Twig_Loader_Array($templates);

  $twig = new Twig_Environment($loader);

  $twig->addFunction(new Twig_Function('webflow_render', 'webflow_render'));


  $GLOBALS['wf_element_index'] = [];

  add_action('enter_context', function () use ($elements, $twig) {
    $max_similarity = 0;
    $matches = [];

    // error_log(json_encode(context()->keys));

    foreach ($elements as $i => $e) {
      $similarity = context()->match($e->keys);

      if (!$e->index) {
        $e->index = $i;
      }

      // error_log($e->class . ' => ' . $similarity);

      if (!$matches['' . $similarity]) {
        $matches['' . $similarity] = [];
      }

      $matches['' . $similarity][] = $e;

      if ($e->rendering) {
        continue;
      }

      if ($similarity > $max_similarity) {
        $max_similarity = $similarity;
      }
    }

    if ($max_similarity <= 0) {
      return;
    }

    $match = $matches['' . $max_similarity];

    if (!$match || sizeof($match) < 1) {
      return;
    }

    usort($match, function ($a, $b) {
      return $a->index - $b->index;
    });

    // error_log(json_encode($match));

    $key = md5(implode('-', array_map(function ($e) {
      return $e->class;
    }, $match)));

    if (!$GLOBALS['wf_element_index'][$key]) {
      $GLOBALS['wf_element_index'][$key] = 0;
    }

    $index = $GLOBALS['wf_element_index'][$key];

    $element = $match[$index % sizeof($match)];

    if ($element->rendering) {
      return;
    }

    error_log($element->class . ' - ' . $element->index . ' - ' . $key . ' (' . $max_similarity . ') => ' . $index . ' / ' . sizeof($match));

    // error_log($element->html);
    // echo $index;

    $element->rendering = true;

    echo $twig->render($element->class, array('_context' => new RenderContext(), 'title' => 'test'));
    
    $element->rendering = false;

    $GLOBALS['wf_element_index'][$key]++;
  });
}
