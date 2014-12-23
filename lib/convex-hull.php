<?php
/**
 * Convex hull calculator
 *
 * convex_hull is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; version 3 of the License.
 *
 * convex_hull is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with convex_hull; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Representation of a convex hull, which is calculated based on a given set of 
 * points.
 *
 * The algorithm used to calculate the convex hull is QuickHull.
 * 
 * @author Jakob Westhoff <jakob@php.net>
 * @license GPLv3
 */
class ConvexHull 
{
    /**
     * Set of points provided as input for the calculation.
     * 
     * @var array( array( float, float ) )
     */
    protected $inputPoints;

    /**
     * The points of the convex hull after the quickhull algorithm has been 
     * executed. 
     * 
     * @var array( array( float, float ) )
     */
    protected $hullPoints;


    /**
     * Construct a new ConvexHull object using the given points as input. 
     * 
     * @param array $points 
     */
    public function __construct( array $pofloats ) 
    {
        $this->inputPoints = $pofloats;
        $this->hullPoints = null;
    }

    /**
     * Return the pofloats of the convex hull.
     *
     * The pofloats will be ordered to form a clockwise defined polygon path 
     * around the convex hull. 
     * 
     * @return array( array( float, float ) );
     */
    public function getHullPoints() 
    {
        if ( $this->hullPoints === null ) 
        {
            // Initial run with max and min x value points. 
            // These points are guaranteed to be points of the convex hull
            // Initially the points on both sides of the line are processed.
            $maxX = $this->getMaxXPoint();
            $minX = $this->getMinXPoint();
            $this->hullPoints = array_merge( 
                $this->quickHull( $this->inputPoints, $minX, $maxX ),
                $this->quickHull( $this->inputPoints, $maxX, $minX )
            );
        }

        return $this->hullPoints;
    }

    /**
     * Return the points provided as input point set. 
     * 
     * @return array( array( float, float ) )
     */
    public function getInputPoints() 
    {
        return $this->inputPoints;
    }

    /**
     * Find and return the point with the maximal X value. 
     * 
     * @return array( float, float )
     */
    protected function getMaxXPoint() 
    {
        $max = $this->inputPoints[0];
        foreach( $this->inputPoints as $p ) 
        {
            if ( $p[0] > $max[0] ) 
            {
                $max = $p;
            }
        }
        return $max;
    }

    /**
     * Find and return the point with the minimal X value. 
     * 
     * @return array( float, float )
     */
    protected function getMinXPoint() 
    {
        $min = $this->inputPoints[0];
        foreach( $this->inputPoints as $p ) 
        {
            if ( $p[0] < $min[0] ) 
            {
                $min = $p;
            }
        }
        return $min;
    }

    /**
     * Calculate a distance indicator between the line defined by $start and 
     * $end and an arbitrary $point.
     *
     * The value returned is not the correct distance value, but is sufficient 
     * to determine the point with the maximal distance from the line. The 
     * returned distance indicator is therefore directly relative to the real 
     * distance of the point. 
     *
     * The returned distance value may be positive or negative. Positive values 
     * indicate the point is left of the specified vector, negative values 
     * indicate it is right of it. Furthermore if the value is zero the point 
     * is colinear to the line.
     * 
     * @param float $start 
     * @param float $end 
     * @param float $point 
     * @return float
     */
    protected function calculateDistanceIndicator( array $start, array $end, array $point ) 
    {
        /*
         * The real distance value could be calculated as follows:
         * 
         * Calculate the 2D Pseudo crossproduct of the line vector ($start 
         * to $end) and the $start to $point vector. 
         * ((y2*x1) - (x2*y1))
         * The result of this is the area of the parallelogram created by the 
         * two given vectors. The Area formula can be written as follows:
         * A = |$start->$end| * h
         * Therefore the distance or height is the Area divided by the length 
         * of the first vector. This division is not done here for performance 
         * reasons. The length of the line does not change for each of the 
         * comparison cycles, therefore the resulting value can be used to 
         * finde the point with the maximal distance without performing the 
         * division.
         *
         * Because the result is not returned as an absolute value its 
         * algebraic sign indicates of the point is right or left of the given 
         * line.
         */

        $vLine = array( 
            $end[0] - $start[0],
            $end[1] - $start[1]
        );

        $vPoint = array( 
            $point[0] - $start[0],
            $point[1] - $start[1]
        );

        return ( ( $vPoint[1] * $vLine[0] ) - ( $vPoint[0] * $vLine[1] ) );
    }

    /**
     * Calculate the distance indicator for each given point and return an 
     * array containing the point and the distance indicator. 
     *
     * Only points left of the line will be returned. Every point right of the 
     * line or colinear to the line will be deleted.
     * 
     * @param array $start 
     * @param array $end 
     * @param array $points 
     * @return array( array( point, distance ) )
     */
    protected function getPointDistanceIndicators( array $start, array $end, array $points ) 
    {
        $resultSet = array();

        foreach( $points as $p ) 
        {
            if ( ( $distance = $this->calculateDistanceIndicator( $start, $end, $p ) ) > 0 ) 
            {
                $resultSet[] = array( 
                    'point'    => $p,
                    'distance' => $distance
                );
            }
            else 
            {
                continue;
            }
        }

        return $resultSet;
    }

    /**
     * Get the point which has the maximum distance from a given line.
     *
     * @param array $pointDistanceSet 
     * @return array( float, float )
     */
    protected function getPointWithMaximumDistanceFromLine( array $pointDistanceSet ) 
    {
        $maxDistance = 0;
        $maxPoint    = null;

        foreach( $pointDistanceSet as $p ) 
        {
            if ( $p['distance'] > $maxDistance )
            {
                $maxDistance = $p['distance'];
                $maxPoint    = $p['point'];
            }
        }

        return $maxPoint;
    }

    /**
     * Extract the points from a point distance set. 
     * 
     * @param array $pointDistanceSet 
     * @return array
     */
    protected function getPointsFromPointDistanceSet( $pointDistanceSet ) 
    {
        $points = array();

        foreach( $pointDistanceSet as $p ) 
        {
            $points[] = $p['point'];
        }

        return $points;
    }

    /**
     * Execute a QuickHull run on the given set of points, using the provided 
     * line as delimiter of the search space.
     *
     * Only points left of the given line will be analyzed. 
     * 
     * @param array $points 
     * @param array $start 
     * @param array $end 
     * @return array
     */
    protected function quickHull( array $points, array $start, array $end ) 
    {
        $pointsLeftOfLine = $this->getPointDistanceIndicators( $start, $end, $points );
        $newMaximalPoint = $this->getPointWithMaximumDistanceFromLine( $pointsLeftOfLine );
        
        if ( $newMaximalPoint === null ) 
        {
            // The current delimiter line is the only one left and therefore a 
            // segment of the convex hull. Only the end of the line is returned 
            // to not have points multiple times in the result set.
            return array( $end );
        }

        // The new maximal point creates a triangle together with $start and 
        // $end, Everything inside this trianlge can be ignored. Everything 
        // else needs to handled recursively. Because the quickHull invocation 
        // only handles points left of the line we can simply call it for the 
        // different line segements to process the right kind of points.
        $newPoints = $this->getPointsFromPointDistanceSet( $pointsLeftOfLine );
        return array_merge(
            $this->quickHull( $newPoints, $start, $newMaximalPoint ),
            $this->quickHull( $newPoints, $newMaximalPoint, $end )
        );
    }
}