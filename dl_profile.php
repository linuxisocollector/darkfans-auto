<?php
const filename = "posts_all.json";
try {
    $db = new PDO('sqlite:/tmp/database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
$jsonString = file_get_contents(__DIR__ . '/'.filename);

function createSchema() {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY,
        title TEXT,
        url TEXT,
        downloaded INTEGER DEFAULT 0
    )");
}

function insertEntry($id,$url,$title) {
    global $db;
    $stmt = $db->prepare("INSERT INTO posts (id, url, title) VALUES (:id, :url, :title)");
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':url', $url);
    $stmt->bindParam(':title', $title);
    $stmt->execute();
}

function fetch_full_post($id) {
    $url = 'https://api.darkfans.com/api/posts/' . $id;
    $headers = [
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate, br, zstd',
        'Local-Timezone: "2024-09-30T18:51:30.807Z"',
        'AuthToken: ',
        'Origin: https://darkfans.com',
        'DNT: 1',
        'Connection: keep-alive',
        'Referer: https://darkfans.com/',
        'Cookie: ',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-site',
        'Pragma: no-cache',
        'Cache-Control: no-cache',
        'TE: trailers'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
    }
    
    curl_close($ch);
    
    return json_decode($response, true);
}

function setDownloaded($id)  {
    global $db;
    $stmt = $db->prepare("UPDATE posts SET downloaded = 1 WHERE id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
}

function entryExists($id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM posts WHERE id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    return $stmt->fetch();
}

createSchema();

$posts = json_decode($jsonString,true);
foreach ($posts['data'] as $key => $value) {
    $dbEntry = entryExists($value['id']);
    if($dbEntry !== false && $dbEntry['downloaded'] == 1) {
        continue;
    }
    $id = $value['id'];
    $time = $value['timestamp'];
    $url = '';
    if(count($value['resources']) > 1) {
        foreach ($value['resources'] as $key => $resource) {
            if($resource['type'] === 'video') {
                $url = $resource['url'];
                echo "video found";
                break;
            }
        }
        continue; //PROBABLY IMAGE GALERY
     } else {
         $url = $value['resources'][0]['url'];
     }
    $title = 'candyvom - ' . $value['title'] . ' - ' .  date('Y-m-d', $time/1000);
    if($dbEntry === false) {
        insertEntry($id, $url, $title);
    }
    if($value['resources'][0]['type'] === 'image') {
        continue; //im not interested in images for now
    }
    $escapedTitle = str_replace('/', ' ', $title);
    $escapedTitle = escapeshellarg($escapedTitle);
    if(str_starts_with($url, 'https://cdn.scatbook.com')) {
        if($value['resources'][0]['teaser'] === true) {
            $response = fetch_full_post($id);
            $url = $response['resources'][0]['url'];
        }
        $escapedTitle = substr($escapedTitle, 1, -1);
        //download via yt-dlp
        $command = "yt-dlp --downloader ffmpeg -o '/home/git/drm-video-downloader/".$escapedTitle."_%(title)s.%(ext)s' '$url'";
    } else {
        $command = "source /home/git/drm-video-downloader/venv/bin/activate && python /home/git/drm-video-downloader/downloader.py '$url' $escapedTitle";
    }
    echo $command . "\n";
    $output = [];
    exec($command, $output, $return_var);
    if ($return_var !== 0) {
        echo "Command failed with return code: $return_var\n";
        var_dump($output);
        die();
    } else {
        setDownloaded($id);
    }
}
