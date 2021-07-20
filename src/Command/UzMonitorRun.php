<?php

namespace App\Command;

use App\Component\WebClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class UzMonitorRun extends Command
{
    protected $webClient;

    protected static $defaultName = 'uz:monitor:run';

    public static $defaultDescription = 'Polling of uz.booking';

    public function __construct()
    {
        parent::__construct();

        $this->webClient = new WebClient();
    }

    protected function configure(): void
    {
        $this->addArgument('fromCode', InputArgument::REQUIRED);
        $this->addArgument('toCode', InputArgument::REQUIRED);
        $this->addArgument('date', InputArgument::REQUIRED);
        $this->addArgument('passengersNames', InputArgument::REQUIRED);

        $this->addOption('minTime', null, InputOption::VALUE_REQUIRED);
        $this->addOption('placesType', null, InputOption::VALUE_REQUIRED);
        $this->addOption('trainNumbers', null, InputOption::VALUE_REQUIRED);

        $this->addOption('debug', null, InputOption::VALUE_NONE);
        $this->addOption('inTermux', null, InputOption::VALUE_NONE);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $bufferedOutput = new BufferedOutput($output->getVerbosity(), $output->isDecorated(), $output->getFormatter());

        $fromCode = $input->getArgument('fromCode');
        $toCode = $input->getArgument('toCode');
        $date = $input->getArgument('date');
        $passengersNames = array_map('trim', explode(',', $input->getArgument('passengersNames')));

        $minTime = $input->getOption('minTime');
        $placesType = $input->getOption('placesType');
        $trainNumbers = $input->getOption('trainNumbers')
            ? array_map('trim', explode(',', $input->getOption('trainNumbers')))
            : null;
        $debug = $input->getOption('debug');
        $inTermux = $input->getOption('inTermux');

        $bufferedOutput->writeln('Start monitoring...');

        while(true) {

            $bufferedOutput->writeln(date('d.m H:i:s').' New request...');

            $data = [
                'from' => $fromCode,
                'to' => $toCode,
                'date' => $date,
                'time' => $minTime,
            ];

            $debug && $bufferedOutput->writeln('Get trains');

            $trainsResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_search/', $data);

            if (($trainsResponse = json_decode($trainsResponseJson, true)) && isset($trainsResponse['captcha'])) {
                $bufferedOutput->writeln('Got captcha, trying to bypass...');

                $antiCacheKey = explode(' ', microtime())[0];
                file_put_contents('captcha.jpg', $this->webClient->get('https://booking.uz.gov.ua/ru/captcha/?key='.$antiCacheKey));
                file_put_contents('captcha.ogg', $this->webClient->get('https://booking.uz.gov.ua/ru/captcha/audio/?type=ogg&key='.$antiCacheKey));

                exec('ffmpeg -y -hide_banner -loglevel error -i captcha.ogg captcha.wav');

                $debug && $bufferedOutput->writeln('Captcha saved and converted');

                $azureSstToken = $this->webClient->post(
                    'https://northeurope.api.cognitive.microsoft.com/sts/v1.0/issuetoken',
                    [],
                    [
                        'Content-type: application/x-www-form-urlencoded',
                        'Content-Length: 0',
                        'Ocp-Apim-Subscription-Key: b94e841aa8c243528fe7ba19f1fc7068'
                    ],
                    true
                );

                $debug && $bufferedOutput->writeln('Got azure token: ' . $azureSstToken);

                $captchaRecognitionResponse = $this->webClient->post(
                    'https://northeurope.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=ru-RU',
                    file_get_contents('captcha.wav'),
                    [
                        'Content-Type: audio/wave',
                        'Authorization: Bearer ' . $azureSstToken
                    ],
                    true
                );

               $debug && $bufferedOutput->writeln('Recognition API response: ' . $captchaRecognitionResponse);

                $recognitionData = json_decode($captchaRecognitionResponse, true);
                $captchaText = $recognitionData['DisplayText'];

                $captchaText = mb_strtolower($captchaText);
                $captchaText = str_replace(
                    ['один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
                    [1, 2, 3, 4, 5, 6, 7, 8, 9],
                    $captchaText
                );
                $captchaText = preg_replace('~\D~', '', $captchaText);

                $debug && $bufferedOutput->writeln('Resend with captcha text: ' . $captchaText);

                $trainsResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_search/', array_merge($data, [
                    'captcha' => $captchaText
                ]));
            }

            if (empty($trainsResponse = json_decode($trainsResponseJson, true)) || empty($trainsData = $trainsResponse['data'] ?? null)) {
                $this->notifyUnexpectedResponse($bufferedOutput, $inTermux, $trainsResponseJson);

                return Command::FAILURE;
            }

            if (!empty($trainsList = $trainsData['list'] ?? null)) {
                $matchedTrains = $this->withFreePlaces(
                    $this->matchedDepartureTime(
                        $this->matchedPlaceType(
                            $this->matchedTrainNumbers(
                                $trainsList,
                                $trainNumbers
                            ),
                            $placesType
                        ),
                        $minTime
                    )
                );

                if (!empty($matchedTrains)) {
                    $train = reset($matchedTrains);

                    $debug && $bufferedOutput->writeln('Get wagons');

                    $wagonsResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_wagons/', array_merge($data, [
                        'train' => $train['num'],
                        'wagon_type_id' => $placesType,
                    ]));

                    if (empty($wagonsResponse = json_decode($wagonsResponseJson, true)) || empty($wagonsData = $wagonsResponse['data'] ?? null)) {
                        $this->notifyUnexpectedResponse($bufferedOutput, $inTermux, $wagonsResponseJson);

                        return Command::FAILURE;
                    }

                    if (!empty($wagonsList = $wagonsData['wagons'])) {
                        $wagon = reset($wagonsData['wagons']);

                        $debug && $bufferedOutput->writeln('Get places');

                        $wagonResponseJson = $this->webClient->post('https://booking.uz.gov.ua/ru/train_wagon/', array_merge($data, [
                            'train' => $train['num'],
                            'wagon_num' =>  $wagon['num'],
                            'wagon_type' =>  $wagon['type'],
                            'wagon_class' =>  $wagon['class'],
                        ]));

                        if (empty($wagonResponse = json_decode($wagonResponseJson, true)) || empty($wagonData = $wagonResponse['data'] ?? null)) {
                            $this->notifyUnexpectedResponse($bufferedOutput, $inTermux, $wagonResponseJson);

                            return Command::FAILURE;
                        }

                        $placesNumbers = [];
                        $placesData = reset($wagonData['places']);
                        foreach ($passengersNames as $i => $fullName) {
                            if (isset($placesData[$i])) {
                                $placesNumbers[$fullName] = $placesData[$i];
                            } else {
                                break;
                            }
                        }

                        $placesRequest = [];
                        $i = 0;
                        foreach ($placesNumbers as $fullName => $placeNumber) {
                            [$firstName, $lastName] = explode(' ', $fullName);
                            $placesRequest[] = [
                                'ord' => $i,
                                'from' => $fromCode,
                                'to' => $toCode,
                                'train' => $train['num'],
                                'date' => $date,
                                'wagon_num' => $wagon['num'],
                                'wagon_class' => $wagon['class'],
                                'wagon_type' => $wagon['type'],
                                'wagon_railway' => $wagon['railway'],
                                'charline' => 'A',
                                'firstname' => $firstName,
                                'lastname' => $lastName,
                                'bedding' => '1',
                                'services' => ['M'],
                                'child' => '', //
                                'student' => '', //
                                'reserve' => 0, // 0
                                'place_num' => $placeNumber
                            ];
                            $i++;
                        }

                        $debug && $bufferedOutput->writeln('Put places to cart');

                        $this->webClient->post('https://booking.uz.gov.ua/ru/cart/add/', [
                            'places' => $placesRequest
                        ]);

                        $output->writeln('Ticket put in cart: session id '.$this->webClient->sessionId);

                        if ($inTermux) {
                            // termux version (for android)
                            exec('termux-vibrate -d 5000');
                            exec('termux-notification -c "Tickets in you cart! Session id in a clipboard!"');
                            exec('termux-clipboard-set "' . $this->webClient->sessionId . '"');
                        } else {
                            // mac version
                            exec('say "Tickets in you cart!"');
                        }

                        return Command::SUCCESS;
                    }
                }
            }

            sleep(rand(5, 10));

            if (!empty($trainsData['warning'])) {
                $bufferedOutput->writeln($trainsData['warning']);
            }

            $logName = sprintf('monitorlog_%s_%s_%s', $fromCode, $toCode, $date);

            file_put_contents(ROOT . '/var/' . $logName . '.log', $bufferedOutput->fetch(), FILE_APPEND);
        }
    }

    protected function withFreePlaces(array $trains): array
    {
        return array_filter($trains, function(array $train) {
            return !empty($train['types']);
        });
    }

    protected function matchedDepartureTime(array $trains, ?string $minDepartureTime)
    {
        return is_null($minDepartureTime) ? $trains : array_filter($trains, function(array $train) use($minDepartureTime) {
            return (intval($train['from']['time']) >= intval($minDepartureTime));
        });
    }

    protected function matchedPlaceType(array $trains, ?string $allowedType): array
    {
        return is_null($allowedType) ? $trains : array_filter($trains, function(array $train) use($allowedType) {
            $matchedPlaces = array_filter($train['types'], function(array $type) use($allowedType) {
                return $type['id'] === $allowedType;
            });

            return !empty($matchedPlaces);
        });
    }

    protected function matchedTrainNumbers(array $trains, ?array $trainNumbers): array
    {
        return is_null($trainNumbers) ? $trains : array_filter($trains, function(array $train) use($trainNumbers) {
            return in_array($train['num'], $trainNumbers);
        });
    }

    protected function notifyUnexpectedResponse(OutputInterface $output, bool $inTermux, string $response)
    {
        if ($inTermux) {
            // termux version
            exec('termux-vibrate -d 5000');
            exec('termux-notification -c "Ticket monitor failed, please restart."');
        } else {
            // mac version
            exec('say "Ticket monitor failed, please restart."');
        }

        $output->writeln('Unexpected response!');
        $output->writeln($response);
    }
}
