<?php
namespace Minds\Core\Boost\V3\Ranking;

use Iterator;
use Minds\Core\Boost\V3\Enums\BoostStatus;
use Minds\Core\Boost\V3\Enums\BoostTargetAudiences;
use Minds\Core\Data\MySQL\Client;
use Minds\Exceptions\ServerErrorException;
use PDO;
use PDOException;
use PDOStatement;

class Repository
{
    public function __construct(private ?Client $mysqlClient = null)
    {
        $this->mysqlClient ??= new Client();
    }

    /**
     * Start the transaction
     */
    public function beginTransaction(): void
    {
        if ($this->getMasterConnection()->inTransaction()) {
            throw new PDOException("Cannot initiate transaction. Previously initiated transaction still in progress.");
        }

        $this->getMasterConnection()->beginTransaction();
    }

    /**
     * Commit the transaction
     */
    public function commitTransaction(): void
    {
        $this->getMasterConnection()->commit();
    }

    /**
     * Saves the ranking to the database
     * @param BoostRanking $boostRanking
     */
    public function addBoostRanking(BoostRanking $boostRanking): bool
    {
        $statement = "INSERT INTO boost_rankings (
            guid,
            ranking_open,
            ranking_safe,
            last_updated
        ) VALUES (:guid,:ranking_open,:ranking_safe,NOW())
            ON DUPLICATE KEY UPDATE last_updated=NOW(),ranking_open=:ranking_open,ranking_safe=:ranking_safe";
        $values = [
            'guid' => $boostRanking->getGuid(),
            'ranking_open' => $boostRanking->getRanking(BoostTargetAudiences::CONTROVERSIAL),
            'ranking_safe' => $boostRanking->getRanking(BoostTargetAudiences::SAFE),
        ];

        $stmt = $this->mysqlClient->getConnection(Client::CONNECTION_MASTER)->prepare($statement);
        return $stmt->execute($values);
    }

    /**
     * Returns an iterator of all the available boosts and their share calculations
     * @return Iterator
     * @throws ServerErrorException
     */
    public function getBoostShareRatios(): Iterator
    {
        $stmt = $this->prepareShareRatiosCalculationQuery();
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            yield $this->buildBoostShareModel($row);
        }
    }

    /**
     * Returns an iterator of all the available boosts and their share calculations
     * @param string $guid
     * @return BoostShareRatio|null
     */
    public function getBoostShareRatiosByGuid(string $guid): ?BoostShareRatio
    {
        $stmt = $this->prepareShareRatiosCalculationQuery($guid);
        $stmt->execute(['guid' => $guid]);

        $row = $stmt->fetchAll()[0] ?? null;

        return isset($row) ? $this->buildBoostShareModel($row) : null;
    }

    /**
     * Builds the model from raw row data
     * @param array $row
     * @return BoostShareRatio
     * @throws ServerErrorException
     */
    protected function buildBoostShareModel(array $row): BoostShareRatio
    {
        return new BoostShareRatio(
            guid: $row['guid'],
            targetAudienceShares: [
                BoostTargetAudiences::CONTROVERSIAL => $row['share_ratio_open_audience'],
                BoostTargetAudiences::SAFE => $row['share_ratio_safe_audience'],
            ],
            targetLocation: $row['target_location'],
            targetSuitability: $row['target_suitability'],
        );
    }

    /**
     * Prepares the PDO statement to get the shares of boost deliver
     * @param string|null $guid (optional)
     * @return PDOStatement
     * @throws ServerErrorException
     */
    protected function prepareShareRatiosCalculationQuery(string $guid = null): PDOStatement
    {
        $status = BoostStatus::APPROVED;
        $statement = "SELECT
            guid,
            boosts.target_location,
            boosts.target_suitability,
            (CASE 
                WHEN target_suitability=2 THEN 0 
                WHEN payment_method=1 THEN (daily_bid/total_bids.cash_bids_for_safe) * IF(token_bids_for_safe = 0, 1, 0.67)
                WHEN payment_method=2 THEN (daily_bid/total_bids.token_bids_for_safe) * IF(cash_bids_for_safe = 0, 1, 0.33)
                ELSE 0 END
            ) AS share_ratio_safe_audience,
            (CASE 
                WHEN payment_method=1 THEN (daily_bid/total_bids.cash_bids_for_all) * IF(token_bids_for_all = 0, 1, 0.67)
                WHEN payment_method=2 THEN (daily_bid/total_bids.token_bids_for_all) * IF(cash_bids_for_all = 0, 1, 0.33)
                ELSE 0 END
            ) AS share_ratio_open_audience
        FROM boosts
        LEFT JOIN (
            SELECT 
                target_location,
                SUM(CASE WHEN payment_method=1 AND target_suitability=1 THEN daily_bid ELSE 0 END) as cash_bids_for_safe,
                SUM(CASE WHEN payment_method=1 AND target_suitability=2 THEN daily_bid ELSE 0 END) as cash_bids_for_open,
                SUM(CASE WHEN payment_method=1 THEN daily_bid ELSE 0 END) as cash_bids_for_all,
                SUM(CASE WHEN payment_method=2 AND target_suitability=1 THEN daily_bid ELSE 0 END) as token_bids_for_safe,
                SUM(CASE WHEN payment_method=2 AND target_suitability=2 THEN daily_bid ELSE 0 END) as token_bids_for_open,
                SUM(CASE WHEN payment_method=2 THEN daily_bid ELSE 0 END) as token_bids_for_all
                FROM boosts
                WHERE status = $status
                AND approved_timestamp > DATE_SUB(approved_timestamp, INTERVAL `duration_days` DAY)
                AND approved_timestamp < DATE_ADD(approved_timestamp, INTERVAL `duration_days` DAY)
                GROUP BY target_location
            ) AS total_bids 
            ON boosts.target_location = total_bids.target_location
        WHERE status = $status";

        if ($guid) {
            $statement .= " AND guid=:guid";
        }

        return $this->mysqlClient->getConnection(Client::CONNECTION_REPLICA)->prepare($statement);
    }

    /**
     * Returns the writer connection
     * @return PDO
     */
    protected function getMasterConnection(): PDO
    {
        return $this->mysqlClient->getConnection(Client::CONNECTION_MASTER);
    }
}
