function asanaRequest($methodPath, $httpMethod = 'GET', $body = null)
{
	$apiKey = 2b58741569613373c5c8e2d443af42ee; /// Get it from http://app.asana.com/-/account_api

	$url = "https://app.asana.com/api/1.0/$methodPath";
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ; 
	curl_setopt($ch, CURLOPT_USERPWD, $apiKey); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
    	
    // SSL cert of Asana is selfmade
    curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
	
	if ($body)
	{
		if (!is_string($body))
		{
			$body = json_encode($body);
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	}
	
	$data = curl_exec($ch);
    	$error = curl_error($ch);
	curl_close($ch);
    
	$result = json_decode($data, true);
	return $result;
}
 
function createTask($workspaceId, $projectId, $task)
{
	$data = array('data' => $task);
	$result = asanaRequest("workspaces/$workspaceId/tasks", 'POST', $data);
	if ($result['data'])
	{
		$newTask = $result['data'];
		$newTaskId = $newTask['id'];
		$data = array('data' => array('project' => $projectId));
		$result = asanaRequest("tasks/$newTaskId/addProject", 'POST', $data);
		return $newTask;
	}
	
	return $result;
}

function copySubtasks($taskId, $newTaskId, $failsafe) {

    $failsafe++;
    if ($failsafe > 10) {
        return FALSE;
    }
    
    // GET subtasks of task
    $result = asanaRequest("tasks/$taskId/subtasks");
    $subtasks = $result["data"];

    
    if ($subtasks){     // does subtask exist?
        for ($i= count($subtasks) - 1; $i >= 0; $i--) {
            
            $subtask = $subtasks[$i];
            $subtaskId = $subtask['id'];

            // get data for subtask
            $result = asanaRequest("tasks/$subtaskId?opt_fields=name,due_on,assignee_status,notes,assignee");
            $newSubtask = $result['data'];
            unset($newSubtask["id"]);
            $newSubtask["assignee"] = $newSubtask["assignee"]["id"];
            
            // create Subtask
            $data = array('data' => $newSubtask );
            $result = asanaRequest("tasks/$newTaskId/subtasks", 'POST', $data);

            // add History
            $newSubId = $result["data"]["id"];
            copyHistory($subtaskId, $newSubId);

            // subtask of subtask?
            copySubtasks($subtaskId, $result["data"]["id"], $failsafe);

        }    
    }
            
    
}

function copyHistory($taskId, $newTaskId) {

	$result = asanaRequest("tasks/$taskId/stories");
	$comments = array();
	foreach ($result['data'] as $story){
		$date = date('l M d, Y h:i A', strtotime($story['created_at']));
		$comment = " ­\n" . $story['created_by']['name'] . ' on ' . $date . ":\n" . $story['text'];
		$comments[] = $comment;
	}
	$comment = implode("\n----------------------", $comments);
	$data = array('data' => array('text' => $comment));
	$result = asanaRequest("tasks/$newTaskId/stories", 'POST', $data);
        
}

function copyTags ($taskId, $newTaskId, $newworkspaceId) {
    
    // GET Tags
    $result = asanaRequest("tasks/$taskId/tags");
    
    if($result["data"]){ // are there any tags?
        $tags = $result["data"];
        for ($i = count ($tags) - 1; $i >= 0; $i--) {
           
            $tag = $tags[$i];
            $tagName = $tag["name"];

            // does tag exist?
            $result = asanaRequest("workspaces/$newworkspaceId/tags");
            $tagisset = false;
            $existingtags = $result["data"];
            for($j = count($existingtags) - 1; $j >= 0; $j--) {
                $existingtag = $existingtags[$j];
                
                if ($tagName == $existingtag["name"]) {
                    $tagisset = true;
                    $tagId = $existingtag["id"];
                    break;
                }
            }

            if (!$tagisset) {
     
                print "tag does not exist in workspace";
                $data = array('data' => array('name' => $tagName));
                $result = asanaRequest("workspaces/$newworkspaceId/tags", "POST", $data);
                $tagId = $result["data"]["id"];

            }

            $data = array("data" => array("tag" => $tagId));
            $result = asanaRequest("tasks/$newTaskId/addTag", "POST", $data);
           
        }
    }
}

function createTag($tagName, $workspaceId, $newTaskId) {

    $data = array('data' => array('name' => $tagName));
    $result = asanaRequest("workspaces/$workspaceId/tags", "POST", $data);

    $data = array("data" => array("tag" => $result["data"]["id"]));
    $result = asanaRequest("tasks/$newTaskId/addTag", "POST", $data);

}
 
function copyTasks($fromProjectId, $toProjectId)
{
    // GET Project
	$result = asanaRequest("projects/$toProjectId");
	if (!$result['data'])
	{
        Print "Error Loading Project!";
		return;
	}
    $workspaceId = $result['data']['workspace']['id'];
  	
    // GET Project tasks
    $result = asanaRequest("projects/$fromProjectId/tasks?opt_fields=name,due_on,assignee_status,notes,assignee,completed");
	$tasks = $result['data'];
	
    // copy Tasks
    for ($i = count($tasks) - 1; $i >= 0; $i--)
	{
		$task = $tasks[$i];
		$newTask = $task;
		unset($newTask['id']);
		$newTask['assignee'] = $newTask['assignee']['id'];
		foreach ($newTask as $key => $value)
		{
			if (empty($value))
			{
				unset($newTask[$key]);
			}
		}
		$newTask = createTask($workspaceId, $toProjectId, $newTask);
        
		if ($newTask['id'])
		{
            
            //copy history
			$taskId = $task['id'];
            $newTaskId = $newTask['id'];
			copyHistory($taskId, $newTaskId);
            
            //copy tags
            copyTags($taskId, $newTaskId, $workspaceId);

            //implement copying of subtasks
            $failsafe = 0;
            copySubtasks($taskId, $newTaskId, $failsafe);

		}
	}
}


/// Sample usage
copyTasks(12345678910, 98765432110); /// Get those numbers from the URL of the project