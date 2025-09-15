<?php

namespace trntv\glide\actions;

use Symfony\Component\HttpFoundation\Request;
use Yii;
use yii\base\Action;
use yii\web\Response;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * @author Eugene Terentev <eugene@terentev.net>
 */
class GlideAction extends Action
{
    /**
     * @var string
     */
    public $component = 'glide';

    /**
     * @param $path
     * @return Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     * @throws NotSupportedException
     * @throws \League\Flysystem\FileNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    public function run($path)
    {
        $server = $this->getServer();

        // Use underlying filesystems (Flysystem) for existence checks to be compatible with Glide v3 servers
        $sourceFs = $server->getSource();
        if (!$sourceFs->has($path)) {
            throw new NotFoundHttpException;
        }

        $cacheFs = $server->getCache();
        // If cached version exists but source is newer or equal, delete cache so it will be regenerated
        if ($cacheFs->has($path) && $sourceFs->getTimestamp($path) >= $cacheFs->getTimestamp($path)) {
            $server->deleteCache($path);
        }

        if ($this->getComponent()->signKey) {
            $request = Request::create(Yii::$app->request->getUrl());
            if (!$this->validateRequest($request)) {
                throw new BadRequestHttpException('Wrong signature');
            };
        }

        try {
            $response = Yii::$app->response;
            $response->format = Response::FORMAT_RAW;

            // Generate the image (this will populate cache)
            $path = $server->makeImage($path, Yii::$app->request->get());

            // Serve the cached image stream
            $response->headers->add('Content-Type', $cacheFs->getMimetype($path));
            $response->headers->add('Content-Length', $cacheFs->getSize($path));
            $response->headers->add('Cache-Control', 'max-age=31536000, public');
            $response->headers->add('Expires', (new \DateTime('UTC + 1 year'))->format('D, d M Y H:i:s \G\M\T'));

            $response->stream = $cacheFs->readStream($path);

            return $response;
        } catch (\Exception $e) {
            throw new NotSupportedException($e->getMessage());
        }
    }

    /**
     * @return \League\Glide\Server
     * @throws \yii\base\InvalidConfigException
     */
    protected function getServer()
    {
        return $this->getComponent()->getServer();
    }

    /**
     * @return \trntv\glide\components\Glide;
     * @throws \yii\base\InvalidConfigException
     */
    public function getComponent()
    {
        return Yii::$app->get($this->component);
    }

    /**
     * @param Request $request
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function validateRequest(Request $request)
    {
        return $this->getComponent()->validateRequest($request);
    }
}
